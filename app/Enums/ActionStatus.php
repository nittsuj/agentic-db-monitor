<?php

declare(strict_types=1);

namespace App\Enums;

enum ActionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Executed = 'executed';
    case Failed = 'failed';
}
