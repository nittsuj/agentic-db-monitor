<?php

declare(strict_types=1);

namespace App\Livewire\Approvals;

use App\Enums\ActionDecision;
use App\Enums\ActionStatus;
use App\Models\ActionLog;
use App\Models\PendingAction;
use App\Services\Database\MutationExecutor;
use Illuminate\View\View;
use Livewire\Component;

class ApprovalQueue extends Component
{
    public function approve(string $id): void
    {
        $action = PendingAction::where('status', ActionStatus::Pending)->findOrFail($id);

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
        } else {
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
        }
    }

    public function deny(string $id): void
    {
        $action = PendingAction::where('status', ActionStatus::Pending)->findOrFail($id);

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
    }

    public function render(): View
    {
        $actions = PendingAction::where('status', ActionStatus::Pending)
            ->latest('proposed_at')
            ->get();

        return view('livewire.approvals.approval-queue', [
            'actions' => $actions,
        ]);
    }
}
