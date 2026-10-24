<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much the monitoring user can change on the target, as found by the
 * connection pre-flight.
 */
enum PrivilegeLevel: string
{
    case ReadOnly = 'read_only';
    case Write = 'write';
    case Admin = 'admin';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::ReadOnly => 'Read-only',
            self::Write => 'Bisa menulis data',
            self::Admin => 'Superuser / admin',
            self::Unknown => 'Tidak diketahui',
        };
    }
}
