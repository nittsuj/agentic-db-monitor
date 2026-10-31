<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Database\Ssh\SshTunnelConfig;
use App\Services\Database\Ssh\SshTunnelManager;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class SshTunnelTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adbm-ssh-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_config_reads_enabled_options_and_decrypts_secrets(): void
    {
        $config = SshTunnelConfig::fromOptions(['ssh' => [
            'enabled' => true,
            'host' => 'bastion.example.com',
            'port' => 2222,
            'username' => 'tunnel',
            'auth' => 'key',
            'private_key' => SshTunnelConfig::encrypt('KEY'),
            'passphrase' => SshTunnelConfig::encrypt('phrase'),
        ]]);

        $this->assertNotNull($config);
        $this->assertSame('bastion.example.com', $config->host);
        $this->assertSame(2222, $config->port);
        $this->assertSame('KEY', $config->privateKey);
        $this->assertSame('phrase', $config->askpassSecret());
    }

    public function test_config_is_null_when_disabled_or_absent(): void
    {
        $this->assertNull(SshTunnelConfig::fromOptions([]));
        $this->assertNull(SshTunnelConfig::fromOptions(['ssh' => ['enabled' => false, 'host' => 'x']]));
    }

    public function test_key_command_pins_host_keys_and_keeps_secrets_off_the_command_line(): void
    {
        $manager = new SshTunnelManager('ssh', $this->directory);
        $config = new SshTunnelConfig('bastion', 22, 'tunnel', 'key', 'SECRET-KEY', 'SECRET-PHRASE');

        $command = $manager->command($config, 40000, 'db.internal', 5432, '/tmp/key', interactive: true);
        $line = implode(' ', $command);

        $this->assertContains('127.0.0.1:40000:db.internal:5432', $command);
        $this->assertStringContainsString('StrictHostKeyChecking=accept-new', $line);
        $this->assertStringContainsString('UserKnownHostsFile='.$this->directory, $line);
        $this->assertStringContainsString('ExitOnForwardFailure=yes', $line);
        $this->assertStringContainsString('-i /tmp/key', $line);
        $this->assertStringContainsString('BatchMode=no', $line);
        $this->assertSame('tunnel@bastion', end($command));
        $this->assertStringNotContainsString('SECRET', $line);
    }

    public function test_password_command_disables_public_key_auth(): void
    {
        $manager = new SshTunnelManager('ssh', $this->directory);
        $config = new SshTunnelConfig('bastion', 22, 'tunnel', 'password', password: 'SECRET-PW');

        $line = implode(' ', $manager->command($config, 40000, '127.0.0.1', 3306, null, interactive: true));

        $this->assertStringContainsString('PubkeyAuthentication=no', $line);
        $this->assertStringNotContainsString(' -i ', $line);
        $this->assertStringNotContainsString('SECRET', $line);
    }

    public function test_explains_common_ssh_failures(): void
    {
        $manager = new SshTunnelManager('ssh', $this->directory);
        $config = new SshTunnelConfig('bastion', 22, 'tunnel', 'key', 'KEY');

        $this->assertStringContainsString('authentication failed', $manager->explain('tunnel@bastion: Permission denied (publickey).', $config));
        $this->assertStringContainsString('does not match', $manager->explain("@@@ WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED! @@@\nHost key verification failed.", $config));
        $this->assertStringContainsString('could not be resolved', $manager->explain('ssh: Could not resolve hostname bastion: Name or service not known', $config));
        $this->assertStringContainsString('refused', $manager->explain('ssh: connect to host bastion port 22: Connection refused', $config));
        $this->assertStringContainsString('AllowTcpForwarding', $manager->explain('Error: remote port forwarding failed for listen port', $config));
    }

    public function test_open_fails_with_a_clear_error_and_removes_temp_files(): void
    {
        $manager = new SshTunnelManager($this->directory.'/missing-ssh-binary', $this->directory);
        $config = new SshTunnelConfig('bastion', 22, 'tunnel', 'key', "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----", 'phrase');

        try {
            $manager->open($config, 'db', 5432, timeout: 3);
            $this->fail('Expected the tunnel to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SSH tunnel failed', $e->getMessage());
        }

        // Only the pinned known_hosts file remains; key and askpass are gone.
        $this->assertSame(['known_hosts'], array_map(fn ($f) => $f->getFilename(), File::files($this->directory)));
    }

    public function test_state_directory_is_created_with_private_permissions(): void
    {
        $file = (new SshTunnelManager('ssh', $this->directory.'/nested/ssh'))->knownHostsFile();

        $this->assertFileExists($file);
        $this->assertSame(0700, fileperms($this->directory.'/nested/ssh') & 0777);
    }

    public function test_state_directory_that_already_exists_is_reused(): void
    {
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/known_hosts', "bastion ssh-ed25519 AAAA\n");

        $file = (new SshTunnelManager('ssh', $this->directory))->knownHostsFile();

        $this->assertSame("bastion ssh-ed25519 AAAA\n", file_get_contents($file));
    }

    public function test_state_directory_that_cannot_be_created_fails_with_a_clear_error(): void
    {
        // A regular file in the way makes mkdir() fail for a reason other
        // than a concurrent create.
        mkdir($this->directory, 0700, true);
        touch($this->directory.'/blocker');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create the SSH state directory');

        (new SshTunnelManager('ssh', $this->directory.'/blocker/ssh'))->knownHostsFile();
    }

    public function test_open_rejects_incomplete_settings_before_starting_ssh(): void
    {
        $manager = new SshTunnelManager('ssh', $this->directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no private key is stored');

        $manager->open(new SshTunnelConfig('bastion', 22, 'tunnel', 'key'), 'db', 5432);
    }
}
