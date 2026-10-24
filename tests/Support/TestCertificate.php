<?php

declare(strict_types=1);

namespace Tests\Support;

class TestCertificate
{
    /**
     * A throwaway self-signed certificate in PEM form.
     */
    public static function pem(string $commonName = 'adbm-test-ca'): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $commonName], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($certificate, $pem);

        return $pem;
    }
}
