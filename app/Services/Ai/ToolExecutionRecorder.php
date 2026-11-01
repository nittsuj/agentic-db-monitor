<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\ToolExecutionStatus;
use App\Models\ChatMessage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Throwable;

/**
 * Collects the tool calls of one assistant reply while it streams, then saves
 * them as tool execution logs on the assistant message once it exists.
 */
final class ToolExecutionRecorder
{
    /**
     * @var list<array{tool_name: string, arguments: array<array-key, mixed>, result: ?string, status: ToolExecutionStatus, error: ?string, duration_ms: int}>
     */
    private array $executions = [];

    /**
     * @param  array<int, Tool>  $tools
     * @return list<RecordingTool>
     */
    public function wrap(array $tools): array
    {
        return array_values(array_map(fn (Tool $tool): RecordingTool => RecordingTool::wrap($tool, $this), $tools));
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public function record(string $tool, array $arguments, string|ToolOutput|ToolError $output, int $durationMs): void
    {
        [$status, $result, $error] = match (true) {
            $output instanceof ToolError => [ToolExecutionStatus::Error, null, $output->message],
            $output instanceof ToolOutput => [ToolExecutionStatus::Success, $output->result, null],
            default => [ToolExecutionStatus::Success, $output, null],
        };

        $this->executions[] = [
            'tool_name' => $tool,
            'arguments' => $arguments,
            'result' => $result,
            'status' => $status,
            'error' => $error,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * A tool that threw instead of returning (tools without error handling).
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function recordFailure(string $tool, array $arguments, Throwable $exception, int $durationMs): void
    {
        $this->record($tool, $arguments, new ToolError($exception->getMessage()), $durationMs);
    }

    public function hasExecutions(): bool
    {
        return $this->executions !== [];
    }

    /**
     * @return list<array{tool_name: string, arguments: array<array-key, mixed>, result: ?string, status: ToolExecutionStatus, error: ?string, duration_ms: int}>
     */
    public function executions(): array
    {
        return $this->executions;
    }

    /**
     * Save every recorded call on the assistant message, each pointing to the
     * agent that handles its tool, then start empty again.
     */
    public function saveFor(ChatMessage $message, AgentRegistry $agents): void
    {
        $rows = array_map(function (array $execution) use ($agents): array {
            $agent = $agents->forTool($execution['tool_name']);

            return $execution + [
                'loggable_type' => $agent->getMorphClass(),
                'loggable_id' => $agent->getKey(),
            ];
        }, $this->executions);

        $message->toolExecutionLogs()->createMany($rows);

        $this->executions = [];
    }
}
