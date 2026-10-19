<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Tool;

class MetricSummaryTool
{
    public static function make(string $projectId, ?MetricSummaryService $service = null): Tool
    {
        $service ??= app(MetricSummaryService::class);

        return (new Tool)
            ->as('get_metric_summary')
            ->for('Summarise the telemetry recorded for the active project: latest, minimum, maximum, and mean per metric over the last N minutes. Use it to ground an answer in measured data instead of guessing.')
            ->withParameter(new NumberSchema(
                'window_minutes',
                'Look-back window in minutes (default 15)',
                minimum: 1,
                maximum: MetricSummaryService::MAX_WINDOW_MINUTES,
            ))
            ->using(function (int $window_minutes = MetricSummaryService::DEFAULT_WINDOW_MINUTES) use ($service, $projectId): string {
                $window = max(1, min($window_minutes, MetricSummaryService::MAX_WINDOW_MINUTES));

                return json_encode(
                    $service->summarizeById($projectId, $window),
                    JSON_THROW_ON_ERROR
                );
            });
    }
}
