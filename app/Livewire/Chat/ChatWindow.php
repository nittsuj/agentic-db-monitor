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
use Illuminate\View\View;
use Livewire\Component;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Tool;

class ChatWindow extends Component
{
    public string $input = '';

    public string $assistantContent = '';

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
        $this->messages[] = ['role' => 'user', 'content' => $text];

        ChatMessage::create([
            'chat_session_id' => $this->chatSessionId,
            'role' => ChatRole::User,
            'content' => $text,
        ]);

        $project = Project::find($this->activeProjectId);
        $connection = app(TargetConnectionManager::class)->connect($project);
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
        $provider = 'ollama';
        $model = $aiSetting?->model ?? 'llama3.1';
        $baseUrl = $aiSetting?->base_url ?? 'https://ollama.com';
        $apiKey = $aiSetting?->api_key ?? '';

        $systemPrompt = 'You are a database diagnostics assistant. Always start by listing tables (list_tables) before inspecting schemas. Only call get_table_schema for tables returned by list_tables, and limit schema lookups to 2-3 per request. Read-only tools execute automatically. Never generate or execute raw SQL; use only the provided tools.';

        $this->assistantContent = '';

        $stream = Prism::text()
            ->using($provider, $model, ['api_key' => $apiKey, 'url' => $baseUrl])
            ->withSystemPrompt($systemPrompt)
            ->withPrompt($text)
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

        app(TargetConnectionManager::class)->purge($connection->getName());

        $this->assistantContent = '';
    }

    public function render(): View
    {
        return view('livewire.chat.chat-window');
    }
}
