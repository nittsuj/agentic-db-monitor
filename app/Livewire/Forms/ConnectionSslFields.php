<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\SslMode;
use App\Models\Project;
use App\Services\Database\CaCertificateStore;
use Closure;
use Illuminate\Validation\Rule;

/**
 * SSL/TLS fields shared by the create and edit project forms. The values are
 * stored under projects.options['ssl'].
 */
trait ConnectionSslFields
{
    public string $sslMode = 'prefer';

    public string $sslCa = '';

    /**
     * @return array<string, mixed>
     */
    protected function sslRules(): array
    {
        return [
            'sslMode' => ['required', Rule::enum(SslMode::class)],
            'sslCa' => [
                'nullable',
                'string',
                'max:20000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && trim($value) !== '' && ! app(CaCertificateStore::class)->validate($value)) {
                        $fail('Sertifikat CA harus berupa sertifikat PEM yang valid.');
                    }
                },
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    protected function sslOptions(array $existing = []): array
    {
        $ca = trim($this->sslCa);

        return array_merge($existing, [
            'ssl' => [
                'mode' => $this->sslMode,
                'ca' => $ca === '' ? null : $ca,
            ],
        ]);
    }

    protected function fillSslFrom(Project $project): void
    {
        $ssl = $project->options['ssl'] ?? [];

        $this->sslMode = SslMode::tryFrom((string) ($ssl['mode'] ?? ''))?->value ?? SslMode::Prefer->value;
        $this->sslCa = (string) ($ssl['ca'] ?? '');
    }

    /**
     * Managed providers reject or discourage plaintext, so pick an encrypted
     * default for their hostnames. Only an untouched default is changed.
     */
    public function updatedHost(string $host): void
    {
        $managed = ['.supabase.co', '.supabase.com', '.rds.amazonaws.com', '.neon.tech', '.ondigitalocean.com'];

        foreach ($managed as $suffix) {
            if ($this->sslMode === SslMode::Prefer->value && str_ends_with(strtolower(trim($host)), $suffix)) {
                $this->sslMode = SslMode::Require->value;

                return;
            }
        }
    }
}
