<?php

declare(strict_types=1);

namespace App\Services\Database\Ssh;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Bastion settings stored under projects.options['ssh']. The private key,
 * its passphrase, and the password are kept encrypted with APP_KEY because
 * the options column itself is plain JSON.
 */
final class SshTunnelConfig
{
    public const SECRET_FIELDS = ['private_key', 'passphrase', 'password'];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $auth,
        public readonly ?string $privateKey = null,
        public readonly ?string $passphrase = null,
        public readonly ?string $password = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromOptions(array $options): ?self
    {
        $ssh = $options['ssh'] ?? null;

        if (! is_array($ssh) || ! ($ssh['enabled'] ?? false)) {
            return null;
        }

        return new self(
            host: (string) ($ssh['host'] ?? ''),
            port: (int) ($ssh['port'] ?? 22),
            username: (string) ($ssh['username'] ?? ''),
            auth: ($ssh['auth'] ?? 'key') === 'password' ? 'password' : 'key',
            privateKey: self::decrypt($ssh['private_key'] ?? null),
            passphrase: self::decrypt($ssh['passphrase'] ?? null),
            password: self::decrypt($ssh['password'] ?? null),
        );
    }

    public static function encrypt(?string $value): ?string
    {
        return $value === null || $value === '' ? null : Crypt::encryptString($value);
    }

    public static function decrypt(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * The secret ssh must be given interactively, if any.
     */
    public function askpassSecret(): ?string
    {
        return $this->auth === 'password' ? $this->password : $this->passphrase;
    }
}
