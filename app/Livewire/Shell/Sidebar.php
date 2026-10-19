<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Enums\ActionStatus;
use App\Models\AiSetting;
use App\Models\PendingAction;
use App\Models\Project;
use App\Services\Ai\AiProviderConfig;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Sidebar extends Component
{
    public string $projectId = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->projectId = (string) (session('active_project_id') ?? '');
    }

    /**
     * Re-render so the pending-approval badge follows decisions and new
     * proposals without a page refresh.
     */
    #[On('approvals-changed')]
    #[On('ai-provider-changed')]
    public function refreshApprovals(): void
    {
        // Rendering recomputes the badge; nothing else to do.
    }

    public function updatedProjectId(): void
    {
        if ($this->projectId === '') {
            return;
        }

        $this->redirect(route('switch-project', $this->projectId), navigate: true);
    }

    /**
     * Restore the demo series that the seeder owns. The seeder is idempotent,
     * so nothing the user created is touched.
     */
    public function resetDemoData(): void
    {
        Artisan::call('db:seed', [
            '--class' => DemoSeeder::class,
            '--force' => true,
        ]);

        $this->notice = 'Data demo dipulihkan.';
    }

    public function render(): View
    {
        $activeProjectId = session('active_project_id');
        $config = AiProviderConfig::resolve(AiSetting::where('is_active', true)->first());

        return view('livewire.shell.sidebar', [
            'projects' => Project::orderBy('name')->get(),
            'activeProjectId' => $activeProjectId,
            'pendingCount' => $activeProjectId === null
                ? 0
                : PendingAction::query()
                    ->where('project_id', $activeProjectId)
                    ->where('status', ActionStatus::Pending)
                    ->count(),
            'provider' => $config,
            'hasKey' => $config['api_key'] !== '',
        ]);
    }
}
