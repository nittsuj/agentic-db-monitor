<?php

declare(strict_types=1);

namespace App\Services\Database\Ssh;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Opens local port forwards through a bastion with the system OpenSSH client.
 *
 * Secrets never appear on the command line: a key passphrase or login password
 * is handed to ssh through SSH_ASKPASS reading an environment variable, and a
 * private key is written to a 0600 temp file that is removed on close. Host
 * keys are trusted on first use and pinned in an app-owned known_hosts file.
 */
class SshTunnelManager
{
    public function __construct(
        protected ?string $binary = null,
        protected ?string $stateDirectory = null,
    ) {}

    /**
     * @throws RuntimeException when ssh cannot authenticate or forward in time
     */
    public function open(SshTunnelConfig $config, string $remoteHost, int $remotePort, int $timeout = 10): SshTunnel
    {
        $this->assertUsable($config);

        $localPort = $this->freePort();
        $tempFiles = [];
        $environment = [];

        if ($config->auth === 'key') {
            $tempFiles[] = $keyFile = $this->writePrivate('key', $this->normalizeKey((string) $config->privateKey));
        }

        $secret = $config->askpassSecret();

        if ($secret !== null && $secret !== '') {
            $tempFiles[] = $askpass = $this->askpassScript();
            $environment = [
                'SSH_ASKPASS' => $askpass,
                'SSH_ASKPASS_REQUIRE' => 'force',
                'DISPLAY' => ':0',
                'ADBM_SSH_SECRET' => $secret,
            ];
        }

        $command = $this->command(
            config: $config,
            localPort: $localPort,
            remoteHost: $remoteHost,
            remotePort: $remotePort,
            keyFile: $keyFile ?? null,
            interactive: $environment !== [],
            timeout: $timeout,
        );

        $process = new Process($command, null, $environment + ['LC_ALL' => 'C'], null, null);
        $process->start();

        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                $this->cleanup($tempFiles);

                throw new RuntimeException($this->explain($process->getErrorOutput(), $config));
            }

            // ssh binds the forward only after authenticating, so an open
            // port means the bastion accepted us.
            $socket = @fsockopen('127.0.0.1', $localPort, $errno, $error, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return new SshTunnel($localPort, $process, $tempFiles);
            }

            usleep(100_000);
        }

        $process->stop(0);
        $this->cleanup($tempFiles);

        throw new RuntimeException("SSH tunnel to {$config->host}:{$config->port} did not come up within {$timeout}s.");
    }

    /**
     * @return list<string>
     */
    public function command(
        SshTunnelConfig $config,
        int $localPort,
        string $remoteHost,
        int $remotePort,
        ?string $keyFile,
        bool $interactive,
        int $timeout = 10,
    ): array {
        $command = [
            $this->binary ?? (string) config('services.ssh.binary', 'ssh'),
            '-N', '-T',
            '-L', "127.0.0.1:{$localPort}:{$remoteHost}:{$remotePort}",
            '-p', (string) $config->port,
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile='.$this->knownHostsFile(),
            '-o', 'ConnectTimeout='.max(1, $timeout),
            '-o', 'ServerAliveInterval=15',
            '-o', 'ServerAliveCountMax=3',
            '-o', 'BatchMode='.($interactive ? 'no' : 'yes'),
            '-o', 'NumberOfPasswordPrompts=1',
            '-F', 'none',
        ];

        if ($config->auth === 'key') {
            array_push($command, '-i', (string) $keyFile, '-o', 'IdentitiesOnly=yes', '-o', 'PreferredAuthentications=publickey');
        } else {
            array_push($command, '-o', 'PubkeyAuthentication=no', '-o', 'PreferredAuthentications=password,keyboard-interactive');
        }

        $command[] = "{$config->username}@{$config->host}";

        return $command;
    }

    public function knownHostsFile(): string
    {
        $file = $this->stateDirectory().DIRECTORY_SEPARATOR.'known_hosts';

        if (! is_file($file)) {
            touch($file);
            @chmod($file, 0600);
        }

        return $file;
    }

    /**
     * Map ssh's stderr onto a message a user can act on.
     */
    public function explain(string $stderr, SshTunnelConfig $config): string
    {
        $where = "{$config->username}@{$config->host}:{$config->port}";

        return match (true) {
            str_contains($stderr, 'REMOTE HOST IDENTIFICATION HAS CHANGED'),
            str_contains($stderr, 'Host key verification failed') => "SSH host key for {$config->host} does not match the one recorded earlier. If the bastion was rebuilt, remove its line from ".$this->knownHostsFile().'.',
            str_contains($stderr, 'Permission denied') => "SSH authentication failed for {$where}. Check the username and ".($config->auth === 'key' ? 'private key / passphrase.' : 'password.'),
            str_contains($stderr, 'Could not resolve hostname') => "SSH host {$config->host} could not be resolved.",
            str_contains($stderr, 'Connection refused') => "SSH connection to {$config->host}:{$config->port} was refused.",
            str_contains($stderr, 'timed out') => "SSH connection to {$config->host}:{$config->port} timed out.",
            str_contains($stderr, 'invalid format'),
            str_contains($stderr, 'error in libcrypto') => 'The SSH private key could not be read. Paste the full key including the BEGIN/END lines.',
            str_contains($stderr, 'forwarding failed') => "The bastion refused to forward the database port (AllowTcpForwarding may be off on {$config->host}).",
            default => 'SSH tunnel failed: '.(trim($stderr) !== '' ? trim(last(preg_split('/\R/', trim($stderr)))) : 'ssh exited without an error message.'),
        };
    }

    protected function assertUsable(SshTunnelConfig $config): void
    {
        if ($config->host === '' || $config->username === '') {
            throw new RuntimeException('SSH tunnel needs a host and a username.');
        }

        if ($config->auth === 'key' && ($config->privateKey === null || $config->privateKey === '')) {
            throw new RuntimeException('SSH tunnel uses key authentication but no private key is stored.');
        }

        if ($config->auth === 'password' && ($config->password === null || $config->password === '')) {
            throw new RuntimeException('SSH tunnel uses password authentication but no password is stored.');
        }
    }

    protected function freePort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($server === false) {
            throw new RuntimeException("No free local port for the SSH tunnel: {$error}");
        }

        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * OpenSSH rejects keys with CRLF endings or no final newline.
     */
    protected function normalizeKey(string $key): string
    {
        return trim(str_replace("\r\n", "\n", $key))."\n";
    }

    protected function askpassScript(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->writePrivate('askpass', "@echo off\r\necho %ADBM_SSH_SECRET%\r\n", '.cmd');
        }

        $file = $this->writePrivate('askpass', "#!/bin/sh\nprintf '%s\\n' \"\$ADBM_SSH_SECRET\"\n");
        chmod($file, 0700);

        return $file;
    }

    protected function writePrivate(string $prefix, string $contents, string $suffix = ''): string
    {
        $file = $this->stateDirectory().DIRECTORY_SEPARATOR.$prefix.'-'.bin2hex(random_bytes(8)).$suffix;

        // Create with 0600 before writing so the secret is never world-readable.
        touch($file);
        chmod($file, 0600);
        file_put_contents($file, $contents, LOCK_EX);

        return $file;
    }

    protected function stateDirectory(): string
    {
        $directory = $this->stateDirectory ?? (string) config('services.ssh.state_dir', storage_path('app/private/ssh'));

        // Two processes (the collector and a "Test connection" click) can both
        // see the directory missing on a fresh volume. The loser's mkdir() then
        // fails with "File exists", which is fine as long as it now exists, so
        // the warning is silenced and only a directory that truly cannot be
        // created is an error.
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the SSH state directory {$directory}.");
        }

        return $directory;
    }

    /**
     * @param  list<string>  $files
     */
    protected function cleanup(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}
