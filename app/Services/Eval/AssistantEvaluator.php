<?php

declare(strict_types=1);

namespace App\Services\Eval;

use App\Livewire\Chat\ChatWindow;
use App\Services\Ai\AiProviderConfig;
use App\Services\Ai\HiddenTagStreamFilter;
use App\Services\Ai\ProposeMutationTool;
use App\Services\Ai\ToolExecutionRecorder;
use App\Services\Database\Diagnostics\DiagnosticDriver;
use App\Services\Database\DiagnosticTools;
use Illuminate\Database\ConnectionInterface;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

/**
 * Asks the assistant one question the way /chat does (same system prompt,
 * diagnostic tools and step limit) and grades the answer. Nothing is saved:
 * propose_mutation is replaced by a stand-in that only reports the proposal.
 */
final class AssistantEvaluator
{
    /**
     * @param  array{provider: string, model: string, base_url: string, prism: string, api_key: string}  $aiConfig
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly DiagnosticDriver $driver,
        private readonly array $aiConfig,
        private readonly int $timeoutSeconds = AiProviderConfig::TIMEOUT_SECONDS,
        private readonly bool $thinking = true,
    ) {}

    /**
     * Only Ollama lets a request switch a reasoning model's thinking off.
     *
     * @param  array{prism: string}  $aiConfig
     */
    public static function canDisableThinking(array $aiConfig): bool
    {
        return $aiConfig['prism'] === 'ollama';
    }

    /**
     * @return array{id: string, category: string, question: string, passed: bool, reason: string, answer: string, tools: list<string>, seconds: float, error: string|null}
     */
    public function run(EvalCase $case): array
    {
        $recorder = new ToolExecutionRecorder;
        $tools = $recorder->wrap([...DiagnosticTools::for($this->connection, $this->driver), self::proposeMutationStandIn()]);
        $startedAt = microtime(true);
        $answer = '';
        $error = null;

        try {
            $request = Prism::text()
                ->using($this->aiConfig['prism'], $this->aiConfig['model'], [
                    'api_key' => $this->aiConfig['api_key'],
                    'url' => $this->aiConfig['base_url'],
                ])
                ->withClientOptions(['timeout' => $this->timeoutSeconds] + AiProviderConfig::clientOptions())
                ->withSystemPrompt(ChatWindow::systemPrompt(false))
                ->withMessages([new UserMessage($case->question)])
                ->withMaxSteps(6)
                ->withTools($tools);

            if (! $this->thinking && self::canDisableThinking($this->aiConfig)) {
                $request = $request->withProviderOptions(['thinking' => false]);
            }

            $response = $request->asText();

            $filter = new HiddenTagStreamFilter;
            $answer = trim($filter->push($response->text).$filter->flush());
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $toolNames = array_values(array_unique(array_column($recorder->executions(), 'tool_name')));
        $grade = $error === null
            ? $case->grade($answer, $toolNames)
            : ['passed' => false, 'reason' => 'error: '.$error];

        return [
            'id' => $case->id,
            'category' => $case->category,
            'question' => $case->question,
            'passed' => $grade['passed'],
            'reason' => $grade['reason'],
            'answer' => $answer,
            'tools' => $toolNames,
            'seconds' => round(microtime(true) - $startedAt, 1),
            'error' => $error,
        ];
    }

    /**
     * The score over the questions that got an answer. A question that timed out
     * or hit a connection error says nothing about correctness, so it is counted
     * apart; with no answers at all there is no score (percent is null).
     *
     * @param  list<array{passed: bool, error: string|null, seconds: float}>  $results
     * @return array{answered: int, passed: int, unanswered: int, percent: float|null, average_seconds: float|null}
     */
    public static function score(array $results): array
    {
        $answered = array_values(array_filter($results, fn (array $r): bool => $r['error'] === null));
        $passed = count(array_filter($answered, fn (array $r): bool => $r['passed']));

        return [
            'answered' => count($answered),
            'passed' => $passed,
            'unanswered' => count($results) - count($answered),
            'percent' => $answered === [] ? null : round(100 * $passed / count($answered), 1),
            'average_seconds' => $answered === [] ? null : round(array_sum(array_column($answered, 'seconds')) / count($answered), 1),
        ];
    }

    /**
     * Same name, description and parameter as the real tool, so the model sees no
     * difference, but the proposal is neither stored nor executed.
     */
    public static function proposeMutationStandIn(): Tool
    {
        return (new Tool)
            ->as('propose_mutation')
            ->for(ProposeMutationTool::DESCRIPTION)
            ->withParameter(new StringSchema('command', ProposeMutationTool::COMMAND_DESCRIPTION))
            ->using(fn (string $command): string => json_encode([
                'status' => 'pending_approval',
                'message' => 'Action proposed and awaiting user approval. Do not execute or retry; inform the user.',
            ], JSON_THROW_ON_ERROR));
    }
}
