<?php

declare(strict_types=1);

namespace App\Livewire\Chat;

use App\Enums\ChatRole;
use App\Models\AiSetting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Project;
use App\Services\Database\DiagnosticTools;
use App\Services\Database\TargetConnectionManager;
use Illuminate\View\View;
use Livewire\Component;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;

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
