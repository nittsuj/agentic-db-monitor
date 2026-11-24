<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ScanStatus;
use App\Models\ScanRun;
use PHPUnit\Framework\TestCase;

class ScanRunTest extends TestCase
{
    public function test_percent_follows_the_processed_tables(): void
    {
        $run = new ScanRun(['status' => ScanStatus::Running, 'total' => 3, 'processed' => 2]);

        $this->assertSame(66, $run->percent());
    }

    public function test_percent_is_zero_before_the_tables_are_known(): void
    {
        $this->assertSame(0, (new ScanRun(['status' => ScanStatus::Queued, 'total' => 0, 'processed' => 0]))->percent());
    }

    public function test_a_finished_scan_is_full_even_with_no_tables(): void
    {
        $this->assertSame(100, (new ScanRun(['status' => ScanStatus::Done, 'total' => 0, 'processed' => 0]))->percent());
    }

    public function test_only_done_and_failed_scans_are_finished(): void
    {
        $this->assertFalse(ScanStatus::Queued->isFinished());
        $this->assertFalse(ScanStatus::Running->isFinished());
        $this->assertTrue(ScanStatus::Done->isFinished());
        $this->assertTrue(ScanStatus::Failed->isFinished());
    }
}
