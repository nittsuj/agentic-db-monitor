<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ToolExecutionStatus;
use App\Services\Ai\RecordingTool;
use App\Services\Ai\ToolExecutionRecorder;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use RuntimeException;
use Tests\TestCase;

class RecordingToolTest extends TestCase
{
    protected function slowTool(): Tool
    {
        return (new Tool)
            ->as('get_slow_queries')
            ->for('Return the slowest queries.')
            ->withParameter(new NumberSchema('limit', 'Max rows', minimum: 1, maximum: 50))
            ->using(fn (int $limit = 10): string => json_encode(['limit' => $limit]));
    }

    public function test_the_copy_keeps_the_tools_name_description_and_parameters(): void
    {
        $original = $this->slowTool();
        $copy = RecordingTool::wrap($original, new ToolExecutionRecorder);

        $this->assertSame($original->name(), $copy->name());
        $this->assertSame($original->description(), $copy->description());
        $this->assertSame($original->parametersAsArray(), $copy->parametersAsArray());
        $this->assertSame($original->requiredParameters(), $copy->requiredParameters());
    }

    public function test_records_a_call_made_the_way_prism_makes_it(): void
    {
        $recorder = new ToolExecutionRecorder;
        [$tool] = $recorder->wrap([$this->slowTool()]);

        // Prism passes the model's arguments by name; "5" arrives as a string.
        $output = call_user_func_array($tool->handle(...), ['limit' => '5']);

        $this->assertSame('{"limit":5}', $output);

        [$execution] = $recorder->executions();
        $this->assertSame('get_slow_queries', $execution['tool_name']);
        $this->assertSame(['limit' => '5'], $execution['arguments']);
        $this->assertSame('{"limit":5}', $execution['result']);
        $this->assertSame(ToolExecutionStatus::Success, $execution['status']);
        $this->assertNull($execution['error']);
        $this->assertGreaterThanOrEqual(0, $execution['duration_ms']);
    }

    public function test_measures_the_time_the_tool_itself_took(): void
    {
        $recorder = new ToolExecutionRecorder;
        [$tool] = $recorder->wrap([(new Tool)->as('slow')->for('Slow.')->using(function (): string {
            usleep(60_000);

            return 'done';
        })]);

        $tool->handle();

        $this->assertGreaterThanOrEqual(55, $recorder->executions()[0]['duration_ms']);
    }

    public function test_a_tool_error_is_recorded_as_an_error(): void
    {
        $recorder = new ToolExecutionRecorder;
        [$tool] = $recorder->wrap([(new Tool)->as('broken')->for('Fails.')->using(function (): string {
            throw new RuntimeException('Connection refused');
        })]);

        $output = $tool->handle();

        $this->assertInstanceOf(ToolError::class, $output);
        $this->assertSame(ToolExecutionStatus::Error, $recorder->executions()[0]['status']);
        $this->assertNull($recorder->executions()[0]['result']);
        $this->assertStringContainsString('Connection refused', $recorder->executions()[0]['error']);
    }

    public function test_a_tool_that_throws_is_recorded_and_still_throws(): void
    {
        $recorder = new ToolExecutionRecorder;
        [$tool] = $recorder->wrap([(new Tool)->as('strict')->for('Throws.')->withoutErrorHandling()->using(function (): string {
            throw new RuntimeException('Hard failure');
        })]);

        try {
            $tool->handle();
            $this->fail('Expected the exception to pass through.');
        } catch (RuntimeException $e) {
            $this->assertSame('Hard failure', $e->getMessage());
        }

        $this->assertSame(ToolExecutionStatus::Error, $recorder->executions()[0]['status']);
        $this->assertSame('Hard failure', $recorder->executions()[0]['error']);
    }
}
