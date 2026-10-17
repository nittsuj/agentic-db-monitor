<?php

declare(strict_types=1);

namespace App\Enums;

enum ActionCategory: string
{
    case Maintenance = 'maintenance';
    case Ddl = 'ddl';
    case Dml = 'dml';
}
