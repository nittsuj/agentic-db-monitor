<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\Threshold;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;

final class ThresholdBreached extends Notification
{
    public function __construct(
        public readonly Project $project,
        public readonly Threshold $threshold,
        public readonly float $value,
        public readonly CarbonInterface $capturedAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'metric' => $this->threshold->key,
            'operator' => $this->threshold->operator->value,
            'threshold' => $this->threshold->value,
            'value' => $this->value,
            'captured_at' => $this->capturedAt->toIso8601String(),
            'message' => sprintf(
                '%s: %s is %.2f (%s %.2f).',
                $this->project->name,
                $this->threshold->key,
                $this->value,
                $this->threshold->operator->symbol(),
                $this->threshold->value,
            ),
        ];
    }
}
