<?php

declare(strict_types=1);

namespace App\Enums;

enum DbDriver: string
{
    case MySql = 'mysql';
    case PgSql = 'pgsql';

    public function defaultPort(): int
    {
        return match ($this) {
            self::MySql => 3306,
            self::PgSql => 5432,
        };
    }
}
