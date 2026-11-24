<?php

declare(strict_types=1);

namespace App\Enums;

enum ScanStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    /** A finished scan no longer changes, so the page can stop polling it. */
    public function isFinished(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
