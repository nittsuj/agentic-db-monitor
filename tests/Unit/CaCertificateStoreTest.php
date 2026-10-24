<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Database\CaCertificateStore;
use Illuminate\Support\Facades\File;
use Tests\Support\TestCertificate;
use Tests\TestCase;

class CaCertificateStoreTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adbm-ca-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_writes_the_certificate_once_under_a_content_addressed_name(): void
    {
        $store = new CaCertificateStore($this->directory);
        $pem = TestCertificate::pem();

        $first = $store->path($pem);
        $second = $store->path("  {$pem}\n\n");

        $this->assertSame($first, $second);
        $this->assertFileExists($first);
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) file_get_contents($first));
        $this->assertCount(1, File::files($this->directory));
    }

    public function test_different_certificates_get_different_files(): void
    {
        $store = new CaCertificateStore($this->directory);

        $this->assertNotSame($store->path(TestCertificate::pem('a')), $store->path(TestCertificate::pem('b')));
    }

    public function test_validates_pem_certificates(): void
    {
        $store = new CaCertificateStore($this->directory);

        $this->assertTrue($store->validate(TestCertificate::pem()));
        $this->assertFalse($store->validate('not a certificate'));
        $this->assertFalse($store->validate("-----BEGIN CERTIFICATE-----\nZm9v\n-----END CERTIFICATE-----"));
    }
}
