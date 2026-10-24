<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Transport security for a target connection, in libpq vocabulary. MySQL
 * maps the same modes onto its PDO SSL attributes.
 */
enum SslMode: string
{
    case Disable = 'disable';
    case Prefer = 'prefer';
    case Require = 'require';
    case VerifyCa = 'verify-ca';
    case VerifyFull = 'verify-full';

    public function label(): string
    {
        return match ($this) {
            self::Disable => 'Nonaktif (tanpa enkripsi)',
            self::Prefer => 'Prefer (default)',
            self::Require => 'Require (enkripsi, tanpa verifikasi)',
            self::VerifyCa => 'Verify CA',
            self::VerifyFull => 'Verify full (CA + hostname)',
        };
    }

    public function encrypts(): bool
    {
        return in_array($this, [self::Require, self::VerifyCa, self::VerifyFull], true);
    }

    public function verifiesServer(): bool
    {
        return $this === self::VerifyCa || $this === self::VerifyFull;
    }
}
