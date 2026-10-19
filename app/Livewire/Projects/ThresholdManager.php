<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Enums\MetricKey;
use App\Enums\ThresholdOperator;
use App\Models\Threshold;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Threshold — Agentic DB Monitor')]
class ThresholdManager extends Component
{
    public ?string $activeProjectId = null;

    public string $key = 'connection_pool_used';

    public string $operator = 'gt';

    public float $value = 80.0;

    public bool $enabled = true;

    public ?string $editingId = null;

    public function mount(): void
    {
        $this->activeProjectId = session('active_project_id');
    }

    protected function rules(): array
    {
        return [
            'key' => ['required', Rule::in(MetricKey::values())],
            'operator' => ['required', 'in:gt,gte,lt,lte,eq,ne'],
            'value' => ['required', 'numeric'],
            'enabled' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        if ($this->editingId !== null) {
            $threshold = Threshold::where('project_id', $this->activeProjectId)
                ->findOrFail($this->editingId);
            $threshold->update([
                'key' => $this->key,
                'operator' => $this->operator,
                'value' => $this->value,
                'enabled' => $this->enabled,
            ]);
        } else {
            Threshold::create([
                'project_id' => $this->activeProjectId,
                'key' => $this->key,
                'operator' => $this->operator,
                'value' => $this->value,
                'enabled' => $this->enabled,
            ]);
        }

        $this->resetForm();
    }

    public function edit(string $id): void
    {
        $threshold = Threshold::where('project_id', $this->activeProjectId)
            ->findOrFail($id);

        $this->editingId = $threshold->id;
        $this->key = $threshold->key;
        $this->operator = $threshold->operator->value;
        $this->value = (float) $threshold->value;
        $this->enabled = (bool) $threshold->enabled;
    }

    public function delete(string $id): void
    {
        Threshold::where('project_id', $this->activeProjectId)
            ->findOrFail($id)
            ->delete();
    }

    public function toggle(string $id): void
    {
        $threshold = Threshold::where('project_id', $this->activeProjectId)
            ->findOrFail($id);
        $threshold->update(['enabled' => ! $threshold->enabled]);
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->key = 'connection_pool_used';
        $this->operator = 'gt';
        $this->value = 80.0;
        $this->enabled = true;
    }

    public function render(): View
    {
        $thresholds = Threshold::where('project_id', $this->activeProjectId)
            ->orderBy('key')
            ->get();

        return view('livewire.projects.threshold-manager', [
            'thresholds' => $thresholds,
            'operators' => ThresholdOperator::cases(),
            'metricKeys' => MetricKey::cases(),
        ]);
    }
}
