<?php

declare(strict_types=1);

namespace App\Services\LlmHub;

use App\Models\AiSetting;
use App\Services\Ai\AiErrorTranslator;
use App\Services\Ai\AiProviderConfig;
use App\Services\Ai\HiddenTagStreamFilter;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

/**
 * Plain chat (no tools, no target database) against the provider that is
 * active in AI settings, with the themed system prompt from config/llm-hub.php.
 */
final class LlmChatService
{
    public function __construct(private readonly AiErrorTranslator $translator) {}

    public function generate(string $prompt): LlmResult
    {
        $config = $this->providerConfig();

        try {
            $response = $this->request($config, $prompt)->asText();
        } catch (Throwable $exception) {
            throw $this->failure($exception, $config);
        }

        $filter = new HiddenTagStreamFilter;
        $text = $filter->push($response->text).$filter->flush();

        return $this->result($config, $text, $response->usage);
    }

    /**
     * Yields visible text deltas with <think> blocks removed, then returns the
     * final LlmResult (read it with Generator::getReturn()).
     *
     * @return Generator<int, string, mixed, LlmResult>
     */
    public function stream(string $prompt): Generator
    {
        $config = $this->providerConfig();
        $filter = new HiddenTagStreamFilter;
        $text = '';
        $usage = null;

        try {
            foreach ($this->request($config, $prompt)->asStream() as $event) {
                if ($event instanceof StreamEndEvent) {
                    $usage = $event->usage;
                }

                if (! $event instanceof TextDeltaEvent) {
                    continue;
                }

                $visible = $filter->push($event->delta);

                if ($visible !== '') {
                    $text .= $visible;

                    yield $visible;
                }
            }
        } catch (Throwable $exception) {
            throw $this->failure($exception, $config);
        }

        $rest = $filter->flush();

        if ($rest !== '') {
            $text .= $rest;

            yield $rest;
        }

        return $this->result($config, $text, $usage);
    }

    /**
     * Same lookup as ChatWindow: the active row, or the ITS gateway defaults.
     *
     * @return array{provider: string, model: string, base_url: string, prism: string, api_key: string}
     */
    private function providerConfig(): array
    {
        return AiProviderConfig::resolve(AiSetting::where('is_active', true)->first());
    }

    /**
     * @param  array{provider: string, model: string, base_url: string, prism: string, api_key: string}  $config
     */
    private function request(array $config, string $prompt): PendingRequest
    {
        return Prism::text()
            ->using($config['prism'], $config['model'], [
                'api_key' => $config['api_key'],
                'url' => $config['base_url'],
            ])
            ->withClientOptions(AiProviderConfig::clientOptions())
            ->withSystemPrompt((string) config('llm-hub.system_prompt'))
            ->withPrompt($prompt);
    }

    /**
     * @param  array{provider: string, model: string, base_url: string, prism: string, api_key: string}  $config
     */
    private function result(array $config, string $text, ?Usage $usage): LlmResult
    {
        return new LlmResult(
            text: trim($text),
            provider: $config['provider'],
            model: $config['model'],
            promptTokens: $usage?->promptTokens,
            completionTokens: $usage?->completionTokens,
            totalTokens: $usage === null ? null : $usage->promptTokens + $usage->completionTokens,
        );
    }

    /**
     * @param  array{provider: string, model: string, base_url: string, prism: string, api_key: string}  $config
     */
    private function failure(Throwable $exception, array $config): LlmRequestFailedException
    {
        $providerStatus = $exception instanceof PrismException ? $exception->httpStatus : null;

        $status = match (true) {
            $exception instanceof PrismRateLimitedException, $providerStatus === 429 => 429,
            $exception instanceof PrismProviderOverloadedException,
            $exception instanceof ConnectionException,
            $providerStatus !== null && $providerStatus >= 500,
            str_contains(strtolower($exception->getMessage()), 'timed out') => 503,
            // Bad key, refused model, unknown endpoint: the upstream is the problem.
            default => 502,
        };

        // Never the prompt or the key: only what identifies the failure.
        Log::warning('LLM hub request failed.', [
            'provider' => $config['provider'],
            'model' => $config['model'],
            'exception' => $exception::class,
            'provider_status' => $providerStatus,
        ]);

        return new LlmRequestFailedException(
            $status,
            $this->translator->translate($exception),
            $config['provider'],
            $config['model'],
            $exception,
        );
    }
}
