<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\DbDriver;
use App\Enums\SslMode;
use App\Models\Project;
use App\Services\Database\Ssh\SshTunnelConfig;
use Closure;
use Illuminate\Validation\Rule;

/**
 * SSH tunnel (bastion) fields shared by the create and edit project forms.
 * Stored under projects.options['ssh'] with the secrets encrypted. Like the
 * database password, an empty secret field on edit keeps the stored value.
 */
trait ConnectionSshFields
{
    public bool $sshEnabled = false;

    public string $sshHost = '';

    public int $sshPort = 22;

    public string $sshUsername = '';

    public string $sshAuth = 'key';

    public string $sshPrivateKey = '';

    public string $sshPassphrase = '';

    public string $sshPassword = '';

    public bool $sshHasStoredKey = false;

    public bool $sshHasStoredPassword = false;

    /**
     * @return array<string, mixed>
     */
    protected function sshRules(): array
    {
        if (! $this->sshEnabled) {
            return [];
        }

        return [
            'sshHost' => ['required', 'string', 'max:255'],
            'sshPort' => ['required', 'integer', 'between:1,65535'],
            'sshUsername' => ['required', 'string', 'max:255'],
            'sshAuth' => ['required', Rule::in(['key', 'password'])],
            'sshPrivateKey' => [
                Rule::requiredIf(fn (): bool => $this->sshAuth === 'key' && ! $this->sshHasStoredKey),
                'nullable',
                'string',
                'max:20000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && trim($value) !== '' && ! preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/', $value)) {
                        $fail('Private key harus berupa key OpenSSH/PEM lengkap, termasuk baris BEGIN dan END.');
                    }
                },
            ],
            'sshPassphrase' => ['nullable', 'string', 'max:1024'],
            'sshPassword' => [
                Rule::requiredIf(fn (): bool => $this->sshAuth === 'password' && ! $this->sshHasStoredPassword),
                'nullable',
                'string',
                'max:1024',
            ],
        ];
    }

    /**
     * SSL and SSH rules together; the tunnel adds a check to sslMode.
     *
     * @return array<string, mixed>
     */
    protected function connectionRules(): array
    {
        $rules = $this->sslRules() + $this->sshRules();
        $rules['sslMode'][] = $this->tunnelSslRule();

        return $rules;
    }

    /**
     * Through a tunnel the driver connects to 127.0.0.1, so a certificate
     * hostname check can never pass. Postgres verify-ca still checks the
     * chain; mysqlnd always checks the name when verifying.
     */
    protected function tunnelSslRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $this->sshEnabled) {
                return;
            }

            $mode = SslMode::tryFrom((string) $value);
            $driver = DbDriver::tryFrom($this->driver);

            if ($mode === SslMode::VerifyFull && $driver === DbDriver::PgSql) {
                $fail('Verify full tidak bisa dipakai lewat SSH tunnel (hostname menjadi 127.0.0.1). Gunakan Verify CA.');
            } elseif ($mode?->verifiesServer() && $driver === DbDriver::MySql) {
                $fail('Verifikasi sertifikat MySQL tidak bisa dipakai lewat SSH tunnel (hostname menjadi 127.0.0.1). Gunakan Require.');
            }
        };
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    protected function sshOptions(array $existing = []): array
    {
        if (! $this->sshEnabled) {
            // Keep stored settings so re-enabling does not lose the key.
            return isset($existing['ssh']) && is_array($existing['ssh'])
                ? array_merge($existing, ['ssh' => array_merge($existing['ssh'], ['enabled' => false])])
                : $existing;
        }

        $stored = is_array($existing['ssh'] ?? null) ? $existing['ssh'] : [];

        $secret = fn (string $field, string $typed): ?string => $typed !== ''
            ? SshTunnelConfig::encrypt($typed)
            : ($stored[$field] ?? null);

        // The secrets of the method that is not selected are kept as stored
        // (the fields are hidden, so nothing new can be typed into them), so
        // trying the other method and switching back does not lose the key or
        // password. Only the selected method's secret is ever handed to ssh.
        $usesKey = $this->sshAuth === 'key';

        return array_merge($existing, [
            'ssh' => [
                'enabled' => true,
                'host' => trim($this->sshHost),
                'port' => $this->sshPort,
                'username' => trim($this->sshUsername),
                'auth' => $this->sshAuth,
                'private_key' => $usesKey ? $secret('private_key', trim($this->sshPrivateKey)) : ($stored['private_key'] ?? null),
                'passphrase' => $usesKey ? $secret('passphrase', $this->sshPassphrase) : ($stored['passphrase'] ?? null),
                'password' => $usesKey ? ($stored['password'] ?? null) : $secret('password', $this->sshPassword),
            ],
        ]);
    }

    protected function fillSshFrom(Project $project): void
    {
        $ssh = is_array($project->options['ssh'] ?? null) ? $project->options['ssh'] : [];

        $this->sshEnabled = (bool) ($ssh['enabled'] ?? false);
        $this->sshHost = (string) ($ssh['host'] ?? '');
        $this->sshPort = (int) ($ssh['port'] ?? 22);
        $this->sshUsername = (string) ($ssh['username'] ?? '');
        $this->sshAuth = ($ssh['auth'] ?? 'key') === 'password' ? 'password' : 'key';
        $this->sshPrivateKey = '';
        $this->sshPassphrase = '';
        $this->sshPassword = '';
        $this->sshHasStoredKey = ! empty($ssh['private_key']);
        $this->sshHasStoredPassword = ! empty($ssh['password']);
    }

    /**
     * Everything the connection layer reads from projects.options.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    protected function connectionOptions(array $existing = []): array
    {
        return $this->sshOptions($this->sslOptions($existing));
    }
}
