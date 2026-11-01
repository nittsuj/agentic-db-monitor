<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Throwable;

/**
 * A copy of a chat tool that reports each call to a ToolExecutionRecorder.
 *
 * Timing happens here, around the tool's own handler: Prism runs every tool of
 * a step before it streams any of their results, so the time between stream
 * events would give each tool the duration of the whole batch.
 */
final class RecordingTool extends Tool
{
    private ToolExecutionRecorder $recorder;

    public static function wrap(Tool $tool, ToolExecutionRecorder $recorder): self
    {
        $copy = new self;

        // Same class hierarchy, so the protected settings (name, parameters,
        // handler, error handling) can be copied as they are.
        foreach (get_object_vars($tool) as $property => $value) {
            $copy->{$property} = $value;
        }

        $copy->recorder = $recorder;

        return $copy;
    }

    public function handle(...$args): string|ToolOutput|ToolError
    {
        $started = hrtime(true);

        try {
            $output = parent::handle(...$args);
        } catch (Throwable $exception) {
            $this->recorder->recordFailure($this->name(), $args, $exception, self::millisecondsSince($started));

            throw $exception;
        }

        $this->recorder->record($this->name(), $args, $output, self::millisecondsSince($started));

        return $output;
    }

    private static function millisecondsSince(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
