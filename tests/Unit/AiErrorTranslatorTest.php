<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiErrorTranslator;
use Illuminate\Http\Client\ConnectionException;
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
