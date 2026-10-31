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
     * How many times to start ssh when the local port turns out to be taken.
     * freePort() releases the port before ssh binds it, so another process
     * can grab it in between; a fresh port almost always succeeds.
     */
    protected const PORT_ATTEMPTS = 2;

    /**
     * @throws RuntimeException when ssh cannot authenticate or forward in time
     */
    public function open(SshTunnelConfig $config, string $remoteHost, int $remotePort, int $timeout = 10): SshTunnel
    {
        $this->assertUsable($config);

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

        // One deadline for all attempts, so a retry never stretches the
        // caller's timeout.
        $deadline = microtime(true) + $timeout;

        try {
            for ($attempt = 1; ; $attempt++) {
                // ssh logs to this file (-E) rather than stderr: with debug
                // logging on it keeps writing for the life of the tunnel, and a
                // stderr pipe that PHP stops draining would fill up and stall ssh.
                $logFile = $this->writePrivate('log', '');
                $localPort = $this->freePort();

                $command = $this->command(
                    config: $config,
                    localPort: $localPort,
                    remoteHost: $remoteHost,
                    remotePort: $remotePort,
                    keyFile: $keyFile ?? null,
                    interactive: $environment !== [],
                    timeout: $timeout,
                    logFile: $logFile,
                );

                $process = new Process($command, null, $environment + ['LC_ALL' => 'C'], null, null);
                $process->start();

                $failure = $this->awaitForward($process, $logFile, $deadline);

                if ($failure === null) {
                    return new SshTunnel($localPort, $process, [...$tempFiles, $logFile]);
                }

                $this->cleanup([$logFile]);

                if ($failure === false) {
                    throw new RuntimeException("SSH tunnel to {$config->host}:{$config->port} did not come up within {$timeout}s.");
                }

                if (! $this->localPortWasTaken($failure) || $attempt >= static::PORT_ATTEMPTS || microtime(true) >= $deadline) {
                    throw new RuntimeException($this->explain($failure, $config));
                }
            }
        } catch (RuntimeException $e) {
            $this->cleanup($tempFiles);

            throw $e;
        }
    }

    /**
     * Wait for ssh to report the forward.
     *
     * @return string|false|null null once the forward is up; ssh's log when it
     *                           exited; false when the deadline passed (ssh is stopped)
     */
    protected function awaitForward(Process $process, string $logFile, float $deadline): string|false|null
    {
        while (microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                return (string) @file_get_contents($logFile)."\n".$process->getErrorOutput();
            }

            // Read ssh's own account of the forward rather than probing the
            // port: a probe can be answered by another process that holds the
            // port, and it opens a channel to the database for nothing.
            if ($this->forwardIsListening((string) @file_get_contents($logFile))) {
                return null;
            }

            usleep(100_000);
        }

        $process->stop(0);

        return false;
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
        ?string $logFile = null,
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

        if ($logFile !== null) {
            // debug1 is the lowest level that reports the forward's bind.
            array_push($command, '-E', $logFile, '-o', 'LogLevel=DEBUG1');
        }

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
        $stderr = $this->withoutProgressLines($stderr);

        return match (true) {
            str_contains($stderr, 'REMOTE HOST IDENTIFICATION HAS CHANGED'),
            str_contains($stderr, 'Host key verification failed') => "SSH host key for {$config->host} does not match the one recorded earlier. If the bastion was rebuilt, remove its line from ".$this->knownHostsFile().'.',
            str_contains($stderr, 'Permission denied') => "SSH authentication failed for {$where}. Check the username and ".($config->auth === 'key' ? 'private key / passphrase.' : 'password.'),
            str_contains($stderr, 'Could not resolve hostname') => "SSH host {$config->host} could not be resolved.",
            str_contains($stderr, 'Connection refused') => "SSH connection to {$config->host}:{$config->port} was refused.",
            str_contains($stderr, 'timed out') => "SSH connection to {$config->host}:{$config->port} timed out.",
            str_contains($stderr, 'invalid format'),
            str_contains($stderr, 'error in libcrypto') => 'The SSH private key could not be read. Paste the full key including the BEGIN/END lines.',
            str_contains($stderr, 'cannot listen to port'),
            str_contains($stderr, 'Could not request local forwarding') => 'The local port picked for the SSH tunnel was taken by another process. Try again.',
            str_contains($stderr, 'forwarding failed') => "The bastion refused to forward the database port (AllowTcpForwarding may be off on {$config->host}).",
            default => 'SSH tunnel failed: '.(trim($stderr) !== '' ? trim(last(preg_split('/\R/', trim($stderr)))) : 'ssh exited without an error message.'),
        };
    }

    /**
     * ssh logs "Local forwarding listening on ..." before it binds, even when
     * the bind then fails; the port-listener channel only exists once the bind
     * succeeded (checked against OpenSSH 10.0p2).
     */
    protected function forwardIsListening(string $log): bool
    {
        return str_contains($log, 'new port-listener')
            && ! $this->localPortWasTaken($log);
    }

    protected function localPortWasTaken(string $log): bool
    {
        return str_contains($log, 'cannot listen to port')
            || str_contains($log, 'Could not request local forwarding');
    }

    /**
     * Drop debug and routine lines, so explain() matches and reports what
     * actually went wrong.
     */
    protected function withoutProgressLines(string $log): string
    {
        $lines = array_filter(
            preg_split('/\R/', $log) ?: [],
            fn (string $line): bool => ! preg_match('/^(debug\d: |Authenticated to |Warning: Permanently added |Transferred: |Bytes per second: )/', $line),
        );

        return implode("\n", $lines);
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
