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

    /**
     * Log lines captured from OpenSSH 10.0p2 (the php:8.4-cli image the app
     * ships on) with -E and LogLevel=DEBUG1.
     */
    protected const LOG_FORWARD_UP = <<<'LOG'
        debug1: OpenSSH_10.0p2 Debian-7+deb13u4, OpenSSL 3.5.7 9 Jun 2026
        debug1: Host '[bastion]:22' is known and matches the ED25519 host key.
        Authenticated to bastion ([10.0.0.5]:22) using "publickey".
        debug1: Local connections to 127.0.0.1:15432 forwarded to remote address db:5432
        debug1: Local forwarding listening on 127.0.0.1 port 15432.
        debug1: channel 0: new port-listener [port listener] (inactive timeout: 0)
        LOG;

    protected const LOG_PORT_TAKEN = <<<'LOG'
        debug1: OpenSSH_10.0p2 Debian-7+deb13u4, OpenSSL 3.5.7 9 Jun 2026
        Authenticated to bastion ([10.0.0.5]:22) using "publickey".
        debug1: Local connections to 127.0.0.1:15433 forwarded to remote address db:5432
        debug1: Local forwarding listening on 127.0.0.1 port 15433.
        bind [127.0.0.1]:15433: Address already in use
        channel_setup_fwd_listener_tcpip: cannot listen to port: 15433
        Could not request local forwarding.
        LOG;

    protected const LOG_WRONG_KEY = <<<'LOG'
        debug1: OpenSSH_10.0p2 Debian-7+deb13u4, OpenSSL 3.5.7 9 Jun 2026
        debug1: Host '[bastion]:22' is known and matches the ED25519 host key.
        debug1: No more authentication methods to try.
        tunnel@bastion: Permission denied (publickey,password).
        LOG;

    /**
     * A stand-in for ssh: writes $log to the file given with -E, then either
     * keeps running like a live tunnel or exits like a failed one.
     */
    protected function fakeSsh(string $log, bool $staysUp): string
    {
        File::ensureDirectoryExists($this->directory);
        $script = $this->directory.'/fake-ssh-'.uniqid();
        $end = $staysUp ? 'exec sleep 30' : 'exit 255';

        file_put_contents($script, <<<SH
            #!/bin/sh
            while [ \$# -gt 0 ]; do [ "\$1" = "-E" ] && log="\$2"; shift; done
            cat >> "\$log" <<'EOF'
            {$log}
            EOF
            {$end}
            SH);
        chmod($script, 0755);

        return $script;
    }

    protected function keyConfig(): SshTunnelConfig
    {
        return new SshTunnelConfig('bastion', 22, 'tunnel', 'key', "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----");
    }

    public function test_command_logs_to_a_file_at_debug1_when_given_one(): void
    {
        $manager = new SshTunnelManager('ssh', $this->directory);
        $config = new SshTunnelConfig('bastion', 22, 'tunnel', 'password', password: 'pw');

        $line = implode(' ', $manager->command($config, 40000, 'db', 5432, null, true, logFile: '/tmp/ssh.log'));

        $this->assertStringContainsString('-E /tmp/ssh.log', $line);
        $this->assertStringContainsString('LogLevel=DEBUG1', $line);
    }

    public function test_open_succeeds_once_ssh_reports_the_port_listener(): void
    {
        $manager = new SshTunnelManager($this->fakeSsh(self::LOG_FORWARD_UP, staysUp: true), $this->directory);

        // Nothing listens on the local port here, so this only passes if the
        // readiness check reads ssh's log instead of probing the port.
        $tunnel = $manager->open($this->keyConfig(), 'db', 5432, timeout: 3);

        $this->assertTrue($tunnel->isRunning());
        $this->assertGreaterThan(0, $tunnel->localPort);

        $tunnel->close();

        $this->assertSame(['known_hosts'], $this->remainingFiles());
    }

    public function test_listening_line_alone_is_not_treated_as_success(): void
    {
        // ssh prints this line before the bind, including when the bind fails.
        $log = "Authenticated to bastion ([10.0.0.5]:22) using \"publickey\".\ndebug1: Local forwarding listening on 127.0.0.1 port 15432.";
        $manager = new SshTunnelManager($this->fakeSsh($log, staysUp: true), $this->directory);

        try {
            $manager->open($this->keyConfig(), 'db', 5432, timeout: 1);
            $this->fail('Expected the tunnel not to count as up.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not come up within 1s', $e->getMessage());
        }

        $this->assertSame(['known_hosts'], $this->remainingFiles());
    }

    public function test_open_reports_a_local_port_that_is_already_taken(): void
    {
        $manager = new SshTunnelManager($this->fakeSsh(self::LOG_PORT_TAKEN, staysUp: false), $this->directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local port picked for the SSH tunnel was taken');

        $manager->open($this->keyConfig(), 'db', 5432, timeout: 3);
    }

    public function test_open_explains_failures_from_the_log_file(): void
    {
        $manager = new SshTunnelManager($this->fakeSsh(self::LOG_WRONG_KEY, staysUp: false), $this->directory);

        try {
            $manager->open($this->keyConfig(), 'db', 5432, timeout: 3);
            $this->fail('Expected the tunnel to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SSH authentication failed for tunnel@bastion:22', $e->getMessage());
        }

        $this->assertSame(['known_hosts'], $this->remainingFiles());
    }

    public function test_explain_reports_the_last_line_that_is_not_debug_output(): void
    {
        $manager = new SshTunnelManager('ssh', $this->directory);
        $log = "debug1: OpenSSH_10.0p2\nkex_exchange_identification: Connection closed by remote host\ndebug1: Exit status 255";

        $this->assertSame(
            'SSH tunnel failed: kex_exchange_identification: Connection closed by remote host',
            $manager->explain($log, $this->keyConfig()),
        );
    }

    /**
     * @return list<string>
     */
    protected function remainingFiles(): array
    {
        return array_values(array_filter(
            array_map(fn ($f) => $f->getFilename(), File::files($this->directory)),
            fn (string $name): bool => ! str_starts_with($name, 'fake-ssh-'),
        ));
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

    public function test_binary_and_state_directory_come_from_config(): void
    {
        config([
            'services.ssh.binary' => '/opt/openssh/bin/ssh',
            'services.ssh.state_dir' => $this->directory.'/from-config',
        ]);

        $manager = new SshTunnelManager;
        $command = $manager->command(new SshTunnelConfig('bastion', 22, 'tunnel', 'password', password: 'pw'), 15432, 'db', 5432, null, true);

        $this->assertSame('/opt/openssh/bin/ssh', $command[0]);
        $this->assertSame($this->directory.'/from-config/known_hosts', $manager->knownHostsFile());
    }

    public function test_constructor_arguments_override_config(): void
    {
        config([
            'services.ssh.binary' => '/opt/openssh/bin/ssh',
            'services.ssh.state_dir' => $this->directory.'/from-config',
        ]);

        $manager = new SshTunnelManager('/usr/local/bin/ssh', $this->directory.'/explicit');
        $command = $manager->command(new SshTunnelConfig('bastion', 22, 'tunnel', 'password', password: 'pw'), 15432, 'db', 5432, null, true);

        $this->assertSame('/usr/local/bin/ssh', $command[0]);
        $this->assertSame($this->directory.'/explicit/known_hosts', $manager->knownHostsFile());
    }

    public function test_open_rejects_incomplete_settings_before_starting_ssh(): void
    {
        $manager = new SshTunnelManager('ssh', $this->directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no private key is stored');

        $manager->open(new SshTunnelConfig('bastion', 22, 'tunnel', 'key'), 'db', 5432);
    }
}
