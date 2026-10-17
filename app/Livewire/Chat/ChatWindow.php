<?php

declare(strict_types=1);

namespace App\Livewire\Chat;

use App\Enums\ActionStatus;
use App\Enums\ChatRole;
use App\Models\AiSetting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\PendingAction;
use App\Models\Project;
use App\Services\Database\DiagnosticTools;
use App\Services\Database\StatementClassifier;
use App\Services\Database\TargetConnectionManager;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

class ChatWindow extends Component
{
    public string $input = '';

    public string $assistantContent = '';

    public bool $isSending = false;

    public ?string $errorMessage = null;

    public array $messages = [];

    public ?string $chatSessionId = null;

    public ?string $activeProjectId = null;

    public function mount(): void
    {
        $this->activeProjectId = session('active_project_id');
        $this->loadOrCreateSession();
        $this->loadMessages();
    }

    private function loadOrCreateSession(): void
    {
        if ($this->activeProjectId === null) {
            return;
        }

        $session = ChatSession::where('project_id', $this->activeProjectId)->latest()->first();

        if ($session === null) {
            $session = ChatSession::create([
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

        $this->messages = ChatMessage::where('chat_session_id', $this->chatSessionId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => ['role' => $m->role->value, 'content' => $m->content])
            ->toArray();
    }

    public function send(): void
    {
        $text = trim($this->input);
        if ($text === '' || $this->activeProjectId === null || $this->chatSessionId === null) {
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

            $proposeTool = (new Tool)
                ->as('propose_mutation')
                ->for('Propose a mutating SQL statement (INSERT/UPDATE/DELETE/DDL/maintenance) for explicit user approval. The statement is NOT executed; it is queued for approval. Only use for statements the user has requested.')
                ->withParameter(new StringSchema('command', 'The exact SQL statement to propose'))
                ->using(function (string $command) use ($project, $chatSessionId) {
                    $category = app(StatementClassifier::class)->classify($command);

                    if ($category === null) {
                        return ['status' => 'rejected', 'reason' => 'Statement is read-only or not recognized; use diagnostic tools instead.'];
                    }

                    $action = PendingAction::create([
                        'project_id' => $project->id,
                        'chat_session_id' => $chatSessionId,
                        'engine' => $project->driver->value,
                        'category' => $category,
                        'command' => $command,
                        'status' => ActionStatus::Pending,
                        'proposed_at' => now(),
                    ]);

                    return [
                        'status' => 'pending_approval',
                        'pending_action_id' => $action->id,
                        'category' => $category->value,
                        'message' => 'Action proposed and awaiting user approval. Do not execute or retry; inform the user.',
                    ];
                });

            $tools[] = $proposeTool;

            $aiSetting = AiSetting::where('is_active', true)->first();
            $provider = $aiSetting?->provider ?? 'ollama';
            $model = $aiSetting?->model ?? ($provider === 'openai' ? 'gpt-4.1-mini' : 'llama3.1');
            $baseUrl = $aiSetting?->base_url ?? ($provider === 'openai' ? 'https://api.openai.com/v1' : 'https://ollama.com');

            $apiKey = '';
            if ($aiSetting !== null) {
                try {
                    $apiKey = $aiSetting->api_key ?? '';
                } catch (DecryptException $e) {
                    $apiKey = '';
                }
            }

            $systemPrompt = 'You are a database diagnostics assistant. Use read-only diagnostic tools when they can answer the user\'s question. Only inspect table schemas when relevant, and only for tables returned by list_tables. Read-only tools execute automatically. Never execute mutating SQL. When the user explicitly requests a database change, use propose_mutation to queue it for approval; do not claim it has executed.';

            $history = ChatMessage::where('chat_session_id', $this->chatSessionId)
                ->whereIn('role', [ChatRole::User, ChatRole::Assistant])
                ->latest('created_at')
                ->limit(20)
                ->get()
                ->reverse()
                ->map(fn (ChatMessage $message) => $message->role === ChatRole::User
                    ? new UserMessage($message->content)
                    : new AssistantMessage($message->content))
                ->values()
                ->all();

            $this->assistantContent = '';

            $stream = Prism::text()
                ->using($provider, $model, ['api_key' => $apiKey, 'url' => $baseUrl])
                ->withSystemPrompt($systemPrompt)
                ->withMessages($history)
                ->withTools($tools)
                ->withMaxSteps(6)
                ->asStream();

            foreach ($stream as $event) {
                if ($event instanceof TextDeltaEvent) {
                    $this->assistantContent .= $event->delta;
                    $this->stream('assistantContent', $event->delta);
                }
            }

            if ($this->assistantContent !== '') {
                ChatMessage::create([
                    'chat_session_id' => $this->chatSessionId,
                    'role' => ChatRole::Assistant,
                    'content' => $this->assistantContent,
                ]);
                $this->messages[] = ['role' => 'assistant', 'content' => $this->assistantContent];
            }
        } catch (Throwable $exception) {
            Log::warning('Database assistant request failed.', [
                'project_id' => $this->activeProjectId,
                'exception' => $exception::class,
            ]);
            $this->input = $text;
            $this->assistantContent = '';
            $this->errorMessage = 'The assistant could not complete that request. Check your AI provider settings and database connection, then try again.';
        } finally {
            if ($connectionName !== null) {
                $connectionManager->purge($connectionName);
            }

            $this->assistantContent = '';
            $this->isSending = false;
        }
    }

    public function render(): View
    {
        return view('livewire.chat.chat-window');
    }
}
