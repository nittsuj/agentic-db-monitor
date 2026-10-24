<?php

declare(strict_types=1);

namespace App\Services\Database\Ssh;

use Symfony\Component\Process\Process;

/**
 * A running `ssh -L` forward. The database driver connects to
 * 127.0.0.1:{localPort}; closing stops ssh and removes its temp files.
 */
final class SshTunnel
{
    /**
     * @param  list<string>  $tempFiles
     */
    public function __construct(
        public readonly int $localPort,
        private readonly ?Process $process,
        private array $tempFiles = [],
    ) {}

    public function isRunning(): bool
    {
        return $this->process?->isRunning() ?? false;
    }

    public function close(): void
    {
        if ($this->process?->isRunning()) {
            $this->process->stop(1);
        }

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->tempFiles = [];
    }

    public function __destruct()
    {
        $this->close();
    }
}
