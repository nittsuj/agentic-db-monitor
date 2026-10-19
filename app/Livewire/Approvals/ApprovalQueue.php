<?php

declare(strict_types=1);

namespace App\Livewire\Approvals;

use App\Enums\ActionDecision;
use App\Enums\ActionStatus;
use App\Enums\ChatRole;
use App\Models\ActionLog;
use App\Models\ChatMessage;
use App\Models\PendingAction;
use App\Services\Database\MutationExecutor;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Persetujuan — Agentic DB Monitor')]
class ApprovalQueue extends Component
{
    public ?string $activeProjectId = null;

    public function mount(): void
    {
        $this->activeProjectId = session('active_project_id');
    }

    public function approve(string $id): void
    {
        $action = $this->findPendingAction($id);

        $action->update([
            'status' => ActionStatus::Approved,
            'decided_at' => now(),
        ]);

        $result = app(MutationExecutor::class)->execute($action);

        if ($result['success']) {
            $action->update([
                'status' => ActionStatus::Executed,
                'executed_at' => now(),
            ]);

            ActionLog::create([
                'project_id' => $action->project_id,
                'project_name' => $action->project?->name ?? 'Unknown',
                'engine' => $action->engine,
                'pending_action_id' => $action->id,
                'chat_session_id' => $action->chat_session_id,
                'decision' => ActionDecision::Executed,
                'command' => $action->command,
                'result' => $result['result'],
                'executed_at' => now(),
            ]);

            $this->recordChatOutcome(
                $action,
                "Approval outcome: the user approved `{$action->command}` and it executed successfully ({$result['result']})."
            );

            return;
        }

        $action->update(['status' => ActionStatus::Failed]);

        ActionLog::create([
            'project_id' => $action->project_id,
            'project_name' => $action->project?->name ?? 'Unknown',
            'engine' => $action->engine,
            'pending_action_id' => $action->id,
            'chat_session_id' => $action->chat_session_id,
            'decision' => ActionDecision::Failed,
            'command' => $action->command,
            'error' => $result['error'],
            'executed_at' => now(),
        ]);

        $this->recordChatOutcome(
            $action,
            "Approval outcome: the user approved `{$action->command}` but it failed: {$result['error']}. Do not retry automatically; explain the failure and suggest a corrected plan."
        );
    }

    public function deny(string $id): void
    {
        $action = $this->findPendingAction($id);

        $action->update([
            'status' => ActionStatus::Denied,
            'decided_at' => now(),
        ]);

        ActionLog::create([
            'project_id' => $action->project_id,
            'project_name' => $action->project?->name ?? 'Unknown',
            'engine' => $action->engine,
            'pending_action_id' => $action->id,
            'chat_session_id' => $action->chat_session_id,
            'decision' => ActionDecision::Denied,
            'command' => $action->command,
            'executed_at' => null,
        ]);

        $this->recordChatOutcome(
            $action,
            "Approval outcome: the user denied `{$action->command}`; it was not executed. Acknowledge the decision and propose an alternative only if the user asks."
        );
    }

    public function render(): View
    {
        PendingAction::expireStale();

        $actions = $this->activeProjectId === null
            ? collect()
            : PendingAction::where('project_id', $this->activeProjectId)
                ->where('status', ActionStatus::Pending)
                ->latest('proposed_at')
                ->get();

        $history = $this->activeProjectId === null
            ? collect()
            : ActionLog::where('project_id', $this->activeProjectId)
                ->latest()
                ->limit(10)
                ->get();

        return view('livewire.approvals.approval-queue', [
            'actions' => $actions,
            'history' => $history,
        ]);
    }

    private function findPendingAction(string $id): PendingAction
    {
        return PendingAction::where('project_id', $this->activeProjectId)
            ->where('status', ActionStatus::Pending)
            ->findOrFail($id);
    }

    private function recordChatOutcome(PendingAction $action, string $content): void
    {
        if ($action->chat_session_id === null) {
            return;
        }

        ChatMessage::create([
            'chat_session_id' => $action->chat_session_id,
            'role' => ChatRole::Tool,
            'content' => $content,
            'meta' => [
                'source' => 'approval',
                'pending_action_id' => $action->id,
                'decision' => $action->status->value,
            ],
        ]);
    }
}
