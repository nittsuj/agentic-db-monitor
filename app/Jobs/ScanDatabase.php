<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\ScanRun;
use App\Services\Database\Diagnostics\SchemaFindings;
use App\Services\Database\DiagnosticTools;
use App\Services\Database\TargetConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads the schema of every table in a project's database and records what it
 * finds. Progress is saved after each table, so the page polling the ScanRun
 * shows the bar moving while this runs on the queue worker.
 */
class ScanDatabase implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $scanRunId) {}

    public function handle(TargetConnectionManager $connections): void
    {
        $run = ScanRun::with('project')->find($this->scanRunId);

        if ($run === null || $run->status->isFinished() || $run->project === null) {
            return;
        }

        $project = $run->project;
        $connectionName = $connections->connectionName($project);

        try {
            $connection = $connections->connect($project);
            $driver = DiagnosticTools::driverFor($project->driver->value);
            $tables = $driver->listTables($connection);

            $run->update([
                'status' => ScanStatus::Running,
                'total' => count($tables),
                'processed' => 0,
                'findings' => [],
                'started_at' => now(),
            ]);

            $findings = [];
            $delay = max(0, (int) config('scan.table_delay_ms'));

            foreach ($tables as $index => $table) {
                $run->update(['current_table' => $table]);

                $issues = SchemaFindings::for($driver->getTableSchema($connection, $table));

                if ($issues !== []) {
                    $findings[] = ['table' => $table, 'issues' => $issues];
                }

                $run->update(['processed' => $index + 1, 'findings' => $findings]);

                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }

            $run->update([
                'status' => ScanStatus::Done,
                'current_table' => null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($run, $exception);
        } finally {
            $connections->purge($connectionName);
        }
    }

    /**
     * Also called by the queue when the job dies outside handle(), such as on
     * a timeout, so the page never polls a scan that will not finish.
     */
    public function failed(?Throwable $exception): void
    {
        $run = ScanRun::find($this->scanRunId);

        if ($run !== null && ! $run->status->isFinished()) {
            $this->markFailed($run, $exception);
        }
    }

    private function markFailed(ScanRun $run, ?Throwable $exception): void
    {
        Log::warning('Schema scan failed.', [
            'scan_run_id' => $run->id,
            'project_id' => $run->project_id,
            'exception' => $exception === null ? null : $exception::class,
        ]);

        $run->update([
            'status' => ScanStatus::Failed,
            'current_table' => null,
            // Enough to tell the user what went wrong, never a full stack trace.
            'error' => Str::limit($exception?->getMessage() ?: 'Scan berhenti tanpa pesan error.', 300),
            'finished_at' => now(),
        ]);
    }
}
