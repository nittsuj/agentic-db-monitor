<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MetricSample;
use Illuminate\Console\Command;

class TelemetryClear extends Command
{
    protected $signature = 'telemetry:clear
                            {--hours=48 : Delete samples older than this many hours}';

    protected $description = 'Delete metric samples outside the telemetry retention window.';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');

        if ($hours < 1) {
            $this->error('The --hours option must be at least 1.');

            return self::FAILURE;
        }

        $deleted = MetricSample::query()
            ->where('captured_at', '<=', now()->subHours($hours))
            ->delete();

        $this->info("Deleted {$deleted} metric sample(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}
