<?php

declare(strict_types=1);

namespace App\Enums;

enum ActionDecision: string
{
    case Approved = 'approved';
    case Denied = 'denied';
    case Executed = 'executed';
    case Failed = 'failed';
}
