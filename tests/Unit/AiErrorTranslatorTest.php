<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiErrorTranslator;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use RuntimeException;

class AiErrorTranslatorTest extends TestCase
{
    public function test_translates_unauthorized(): void
    {
        $message = (new AiErrorTranslator)->translate(
            PrismException::providerRequestErrorWithDetails('DeepSeek', 401, 'auth', 'invalid key')
        );

        $this->assertStringContainsString('rejected the API key', $message);
    }

    public function test_reads_the_status_of_a_wrapped_http_error(): void
    {
        // How Prism's Groq driver reports a rejected key: no status of its own.
        $response = new Response(new Psr7Response(401, [], '{"error":{"message":"Invalid API Key","code":"invalid_api_key"}}'));
        $exception = new PrismException('Groq Error [401]: invalid_request_error - Invalid API Key', 0, new RequestException($response));

        $this->assertSame(
            'The AI provider rejected the API key. Check the key in AI settings.',
            (new AiErrorTranslator)->translate($exception),
        );
    }

    public function test_translates_rate_limits(): void
    {
        $this->assertStringContainsString(
            'rate limiting',
            (new AiErrorTranslator)->translate(PrismRateLimitedException::make())
        );

        $this->assertStringContainsString(
            'rate limiting',
            (new AiErrorTranslator)->translate(PrismException::providerRequestErrorWithDetails('DeepSeek', 429, null, null))
        );
    }

    public function test_translates_server_failures(): void
    {
        $this->assertStringContainsString(
            'temporarily unavailable',
            (new AiErrorTranslator)->translate(PrismProviderOverloadedException::make('DeepSeek'))
        );

        $this->assertStringContainsString(
            'temporarily unavailable',
            (new AiErrorTranslator)->translate(PrismException::providerRequestErrorWithDetails('DeepSeek', 503, null, null))
        );
    }

    public function test_translates_network_and_timeout_errors(): void
    {
        $translator = new AiErrorTranslator;

        $this->assertStringContainsString('Could not reach', $translator->translate(new ConnectionException('curl error')));
        $this->assertStringContainsString('Could not reach', $translator->translate(new RuntimeException('cURL error 28: Operation timed out')));
    }

    public function test_falls_back_to_a_generic_message(): void
    {
        $this->assertStringContainsString(
            'could not complete',
            (new AiErrorTranslator)->translate(new RuntimeException('unexpected'))
        );
    }
}
