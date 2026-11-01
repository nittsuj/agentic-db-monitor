<?php

declare(strict_types=1);

namespace App\Livewire\Chat;

use App\Enums\ChatRole;
use App\Enums\ToolSupport;
use App\Models\AiSetting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Project;
use App\Services\Ai\AgentRegistry;
use App\Services\Ai\AiErrorTranslator;
use App\Services\Ai\AiProviderConfig;
use App\Services\Ai\HiddenTagStreamFilter;
use App\Services\Ai\ProposeMutationTool;
use App\Services\Ai\ToolExecutionRecorder;
use App\Services\Ai\ToolSupportVerifier;
use App\Services\Database\DiagnosticTools;
use App\Services\Database\TargetConnectionManager;
use App\Services\Monitoring\MetricSummaryTool;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

#[Title('Chat diagnostik — Agentic DB Monitor')]
class ChatWindow extends Component
{
    public const TOOLS_UNSUPPORTED_WARNING = 'This AI provider does not support function calling, so the diagnostic tools were unavailable and the assistant answered without live database data.';

    public string $input = '';

    public string $assistantContent = '';

    public bool $isSending = false;

    public ?string $errorMessage = null;

    public array $messages = [];

    public ?string $chatSessionId = null;

    public ?string $activeProjectId = null;

    #[Computed]
    public function project(): ?Project
    {
        return Project::find($this->activeProjectId);
    }

    /**
     * Projects offered by the "choose a database first" dialog.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()->orderBy('name')->get(['id', 'name', 'driver', 'database']);
    }

    /** The active AI provider row, switched from the dropdown under the composer. */
    public ?string $aiSettingId = null;

    /**
     * Providers that can be used right now (key saved, or no key needed).
     *
     * @return array<int, array{id: string, label: string}>
     */
    #[Computed]
    public function aiOptions(): array
    {
        return AiSetting::query()
            ->orderBy('provider')
            ->get()
            ->filter(fn (AiSetting $setting): bool => $setting->isUsable())
            ->map(function (AiSetting $setting): array {
                $config = AiProviderConfig::resolve($setting);

                return ['id' => (string) $setting->id, 'label' => $config['provider'].' · '.$config['model']];
            })
            ->values()
            ->all();
    }

    public function updatedAiSettingId(?string $id): void
    {
        $setting = $id === null ? null : AiSetting::find($id);

        if ($setting === null || ! $setting->isUsable()) {
            $this->aiSettingId = AiSetting::where('is_active', true)->value('id');

            return;
        }

        $setting->activate();
        $this->errorMessage = null;
        $this->dispatch('ai-provider-changed');
    }

    public function mount(): void
    {
        $this->aiSettingId = AiSetting::where('is_active', true)->value('id');

        $this->activeProjectId = session('active_project_id');

        // A project deleted in another tab leaves a stale id behind; treat it as none.
        if ($this->activeProjectId !== null && ! Project::whereKey($this->activeProjectId)->exists()) {
            $this->activeProjectId = null;
        }
        $this->loadOrCreateSession();
        $this->loadMessages();

        // "Tanya agen" on the dashboard opens the chat with a pre-filled question;
        // the user still reviews it and presses Kirim.
        $ask = request()->query('ask');

        if (is_string($ask) && trim($ask) !== '') {
            $this->input = Str::limit(trim($ask), 2000, '');
        }
    }

    private function loadOrCreateSession(): void
    {
        $userId = auth()->id();

        // Every chat route requires login. Without a user there is no session
        // to show; querying user_id = null would pick up unassigned old ones.
        if ($this->activeProjectId === null || $userId === null) {
            return;
        }

        // Each user has their own conversation per project.
        $session = ChatSession::where('user_id', $userId)
            ->where('project_id', $this->activeProjectId)
            ->latest()
            ->first();

        if ($session === null) {
            $session = ChatSession::create([
                'user_id' => $userId,
                'project_id' => $this->activeProjectId,
                'title' => 'Chat',
            ]);
        }

        $this->chatSessionId = $session->id;
    }

    private function loadMessages(): void
    {
        if ($this->chatSessionId === null) {
            return;
        }

        $session = ChatSession::withFullHistory()->find($this->chatSessionId);

        $this->messages = ($session?->messages ?? collect())
            ->reject(fn (ChatMessage $message): bool => self::isEmptyReply($message))
            ->map(fn ($m) => ['role' => $m->role->value, 'content' => $m->content])
            ->toArray();
    }

    public function send(): void
    {
        $text = trim($this->input);

        if ($this->activeProjectId === null || $this->chatSessionId === null) {
            // Reopen the "choose a database first" dialog instead of failing silently.
            $this->dispatch('choose-project');

            return;
        }

        if ($text === '') {
            return;
        }

        $this->input = '';
        $this->errorMessage = null;
        $this->isSending = true;
        $this->messages[] = ['role' => 'user', 'content' => $text];

        ChatMessage::create([
            'chat_session_id' => $this->chatSessionId,
            'role' => ChatRole::User,
            'content' => $text,
        ]);

        $connectionManager = app(TargetConnectionManager::class);
        $connectionName = null;

        try {
            $project = Project::findOrFail($this->activeProjectId);
            $connectionName = $connectionManager->connectionName($project);
            $connection = $connectionManager->connect($project);
            $driver = DiagnosticTools::driverFor($project->driver->value);
            $tools = DiagnosticTools::for($connection, $driver);

            $chatSessionId = $this->chatSessionId;

            $tools[] = ProposeMutationTool::make($project, $chatSessionId);
            $tools[] = MetricSummaryTool::make($project->id);

            $aiConfig = AiProviderConfig::resolve(AiSetting::where('is_active', true)->first());

            $verifier = app(ToolSupportVerifier::class);
            $toolSupport = $verifier->cached($aiConfig) ?? ToolSupport::Unknown;

            if ($toolSupport === ToolSupport::Unsupported) {
                $tools = [];
                $this->errorMessage = self::TOOLS_UNSUPPORTED_WARNING;
            }

            // Every tool call of this reply is recorded and saved on the reply.
            $recorder = new ToolExecutionRecorder;
            $tools = $recorder->wrap($tools);

            $history = ChatMessage::where('chat_session_id', $this->chatSessionId)
                ->whereIn('role', [ChatRole::User, ChatRole::Assistant, ChatRole::Tool])
                ->latest('created_at')
                ->limit(20)
                ->get()
                ->reverse()
                ->reject(fn (ChatMessage $message): bool => self::isEmptyReply($message))
                ->map(fn (ChatMessage $message) => match ($message->role) {
                    ChatRole::User => new UserMessage($message->content),
                    ChatRole::Tool => new UserMessage("[tool result] {$message->content}"),
                    default => new AssistantMessage($message->content),
                })
                ->values()
                ->all();

            try {
                $finalContent = $this->streamReply($aiConfig, $tools, $history);
            } catch (Throwable $exception) {
                if ($tools === [] || ! ToolSupportVerifier::indicatesUnsupportedTools($exception)) {
                    throw $exception;
                }

                // FR-27: the provider rejected function calling, so the chat falls
                // back to a tool-free mode and the user is told why.
                $verifier->remember($aiConfig, ToolSupport::Unsupported);
                $this->errorMessage = self::TOOLS_UNSUPPORTED_WARNING;

                $finalContent = $this->streamReply($aiConfig, [], $history);
            }

            // A reply that only called tools still gets a message, so its tool
            // logs have one to belong to; it is not shown as an empty bubble.
            if ($finalContent !== '' || $recorder->hasExecutions()) {
                $reply = ChatMessage::create([
                    'chat_session_id' => $this->chatSessionId,
                    'role' => ChatRole::Assistant,
                    'content' => $finalContent,
                ]);

                $recorder->saveFor($reply, app(AgentRegistry::class));
            }

            if ($finalContent !== '') {
                $this->messages[] = ['role' => 'assistant', 'content' => $finalContent];
            }

            // The agent may have queued a proposal; let the sidebar badge catch up.
            $this->dispatch('approvals-changed');
        } catch (Throwable $exception) {
            Log::warning('Database assistant request failed.', [
                'project_id' => $this->activeProjectId,
                'exception' => $exception::class,
            ]);
            $this->input = $text;
            $this->assistantContent = '';
            $this->errorMessage = app(AiErrorTranslator::class)->translate($exception);
        } finally {
            if ($connectionName !== null) {
                $connectionManager->purge($connectionName);
            }

            $this->assistantContent = '';
            $this->isSending = false;
        }
    }

    /**
     * An assistant message saved only to hold tool logs, with no text to show.
     */
    private static function isEmptyReply(ChatMessage $message): bool
    {
        return $message->role === ChatRole::Assistant && trim((string) $message->content) === '';
    }

    /**
     * Stream one assistant reply and return the content to store.
     *
     * @param  array<string, mixed>  $aiConfig
     * @param  array<int, Tool>  $tools
     * @param  array<int, Message>  $history
     */
    private function streamReply(array $aiConfig, array $tools, array $history): string
    {
        $this->assistantContent = '';

        $request = Prism::text()
            ->using($aiConfig['prism'], $aiConfig['model'], [
                'api_key' => $aiConfig['api_key'],
                'url' => $aiConfig['base_url'],
            ])
            ->withClientOptions(AiProviderConfig::clientOptions())
            ->withSystemPrompt($this->systemPrompt($tools === []))
            ->withMessages($history)
            ->withMaxSteps(6);

        if ($tools !== []) {
            $request = $request->withTools($tools);
        }

        $filter = new HiddenTagStreamFilter;

        foreach ($request->asStream() as $event) {
            if ($event instanceof TextDeltaEvent) {
                $this->appendVisibleDelta($filter->push($event->delta));
            }
        }

        $this->appendVisibleDelta($filter->flush());

        // Safety net for malformed or unbalanced reasoning tags.
        return trim((string) preg_replace('/<think>.*?<\/think>/s', '', $this->assistantContent));
    }

    private function systemPrompt(bool $withoutTools): string
    {
        $prompt = 'You are a database diagnostics assistant. Use read-only diagnostic tools when they can answer the user\'s question. Only inspect table schemas when relevant, and only for tables returned by list_tables. Read-only tools execute automatically. Never execute mutating SQL. When the user explicitly requests a database change, use propose_mutation to queue it for approval; do not claim it has executed.';

        if ($withoutTools) {
            $prompt .= ' You currently have no diagnostic tools available because this provider does not support function calling. Say so plainly, work from the conversation and the telemetry summary you have, and name the read-only checks the user should run manually.';
        }

        return $prompt;
    }

    private function appendVisibleDelta(string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->assistantContent .= $delta;

        // wire:stream inserts content as HTML, so raw model text must never be
        // sent as is. Send the whole reply so far as escaped, rendered Markdown
        // and replace the previous version: formatting shows while it streams.
        $this->stream(
            to: 'assistantContent',
            content: self::renderMarkdown($this->assistantContent),
            replace: true,
        );
    }

    /**
     * Markdown to HTML for assistant replies: raw HTML is escaped and unsafe
     * links are dropped, so a reply cannot inject markup or scripts.
     */
    public static function renderMarkdown(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    public function render(): View
    {
        return view('livewire.chat.chat-window');
    }
}
