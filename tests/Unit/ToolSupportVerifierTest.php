<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ToolSupport;
use App\Services\Ai\ToolSupportVerifier;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Tests\TestCase;

class ToolSupportVerifierTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'provider' => 'openai_compatible',
            'model' => 'qwen3:14b',
            'base_url' => 'https://gateway.test/v1',
            'api_key' => 'test-key',
        ];
    }

    public function test_probe_reports_supported_when_the_provider_accepts_function_calling(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response('data: {"choices":[]}', 200),
        ]);

        $support = app(ToolSupportVerifier::class)->probe($this->config());

        $this->assertSame(ToolSupport::Supported, $support);
    }

    public function test_probe_sends_a_function_calling_payload_with_streaming_enabled(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response('data: {"choices":[]}', 200),
        ]);

        app(ToolSupportVerifier::class)->probe($this->config());

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $this->assertTrue($body['stream']);
            $this->assertSame('qwen3:14b', $body['model']);
            $this->assertSame('function', $body['tools'][0]['type']);
            $this->assertSame('capability_probe', $body['tools'][0]['function']['name']);
            $this->assertSame('Bearer test-key', $request->header('Authorization')[0]);

            return true;
        });
    }

    public function test_probe_reports_unsupported_when_the_provider_rejects_tools(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'error' => ['message' => 'tools is not supported by this model'],
            ], 400),
        ]);

        $support = app(ToolSupportVerifier::class)->probe($this->config());

        $this->assertSame(ToolSupport::Unsupported, $support);
    }

    public function test_probe_reports_unknown_when_the_provider_is_unreachable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $support = app(ToolSupportVerifier::class)->probe($this->config());

        $this->assertSame(ToolSupport::Unknown, $support);
    }

    public function test_probe_reports_unknown_when_the_provider_fails_with_a_server_error(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response('server exploded', 500),
        ]);

        $support = app(ToolSupportVerifier::class)->probe($this->config());

        $this->assertSame(ToolSupport::Unknown, $support);
    }

    public function test_verify_caches_the_verdict_so_the_probe_runs_once(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'error' => ['message' => 'function calling is not supported'],
            ], 400),
        ]);

        $verifier = app(ToolSupportVerifier::class);

        $this->assertNull($verifier->cached($this->config()));
        $this->assertSame(ToolSupport::Unsupported, $verifier->verify($this->config()));
        $this->assertSame(ToolSupport::Unsupported, $verifier->cached($this->config()));
        $this->assertSame(ToolSupport::Unsupported, $verifier->verify($this->config()));

        Http::assertSentCount(1);
    }

    public function test_verify_does_not_cache_an_inconclusive_verdict(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response('server exploded', 500),
        ]);

        $verifier = app(ToolSupportVerifier::class);

        $this->assertSame(ToolSupport::Unknown, $verifier->verify($this->config()));
        $this->assertNull($verifier->cached($this->config()));
    }

    public function test_indicates_unsupported_tools_detects_a_provider_rejection(): void
    {
        $exception = PrismException::providerResponseError(
            'Bad request',
            400,
            '{"error":{"message":"tools is not supported by this model"}}'
        );

        $this->assertTrue(ToolSupportVerifier::indicatesUnsupportedTools($exception));
    }

    public function test_indicates_unsupported_tools_ignores_authentication_failures(): void
    {
        $exception = PrismException::providerResponseError(
            'Unauthorized',
            401,
            '{"error":{"message":"invalid api key"}}'
        );

        $this->assertFalse(ToolSupportVerifier::indicatesUnsupportedTools($exception));
    }

    public function test_indicates_unsupported_tools_detects_a_request_exception(): void
    {
        $response = new ClientResponse(new PsrResponse(
            422,
            [],
            json_encode(['error' => ['message' => 'streaming is not supported']], JSON_THROW_ON_ERROR)
        ));

        $this->assertTrue(ToolSupportVerifier::indicatesUnsupportedTools(new RequestException($response)));
    }

    public function test_indicates_unsupported_tools_ignores_unrelated_exceptions(): void
    {
        $this->assertFalse(ToolSupportVerifier::indicatesUnsupportedTools(new \RuntimeException('boom')));
    }
}
