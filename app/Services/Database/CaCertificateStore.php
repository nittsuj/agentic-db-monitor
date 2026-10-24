<?php

declare(strict_types=1);

namespace App\Services\Database;

/**
 * PDO drivers read CA certificates from disk, so pasted PEM text is written to
 * a file named after its content. Same text, same file: no cleanup is needed
 * when a project changes, and temporary verify connections work too.
 */
class CaCertificateStore
{
    public function __construct(
        protected ?string $directory = null,
    ) {}

    public function path(string $pem): string
    {
        $pem = trim($pem)."\n";
        $directory = $this->directory ?? storage_path('app/private/target-ca');
        $path = $directory.DIRECTORY_SEPARATOR.hash('sha256', $pem).'.pem';

        if (! is_file($path)) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }

            file_put_contents($path, $pem, LOCK_EX);
        }

        return $path;
    }

    public function validate(string $pem): bool
    {
        if (! str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
            return false;
        }

        return @openssl_x509_read(trim($pem)) !== false;
    }

    /**
     * The OS trust store, used by verify modes when no CA is pasted. Providers
     * signed by public CAs (Supabase, Neon, DigitalOcean) verify against it.
     */
    public function systemBundle(): ?string
    {
        $candidates = [
            openssl_get_cert_locations()['default_cert_file'] ?? null,
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
