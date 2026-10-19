<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\ToolSupport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Throwable;

/**
 * Verifies whether the configured provider accepts function-calling payloads
 * (FR-27). The verdict is cached so a chat never pays for the probe twice.
 */
class ToolSupportVerifier
{
    public const CACHE_TTL_SECONDS = 3600;

    public const PROBE_TIMEOUT_SECONDS = 20;

    public const PROBE_CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * The cached verdict for a provider, or null when it was never verified.
     *
     * @param  array<string, mixed>  $config
     */
    public function cached(array $config): ?ToolSupport
    {
        $support = Cache::get($this->cacheKey($config));

        return $support instanceof ToolSupport ? $support : null;
    }

    /**
     * Probe the provider and remember the verdict when it is conclusive.
     *
     * @param  array<string, mixed>  $config
     */
    public function verify(array $config): ToolSupport
    {
        $cached = $this->cached($config);

        if ($cached !== null && $cached !== ToolSupport::Unknown) {
            return $cached;
        }

        $support = $this->probe($config);

        if ($support !== ToolSupport::Unknown) {
            $this->remember($config, $support);
        }

        return $support;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function remember(array $config, ToolSupport $support): void
    {
        Cache::put($this->cacheKey($config), $support, self::CACHE_TTL_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function forget(array $config): void
    {
        Cache::forget($this->cacheKey($config));
    }

    /**
     * Send a minimal function-calling request to the provider endpoint.
     *
     * @param  array<string, mixed>  $config
     */
    public function probe(array $config): ToolSupport
    {
        $baseUrl = trim((string) ($config['base_url'] ?? ''));
        $model = trim((string) ($config['model'] ?? ''));

        if ($baseUrl === '' || $model === '') {
            return ToolSupport::Unknown;
        }

        $request = Http::acceptJson()
            ->timeout(self::PROBE_TIMEOUT_SECONDS)
            ->connectTimeout(self::PROBE_CONNECT_TIMEOUT_SECONDS);

        $apiKey = trim((string) ($config['api_key'] ?? ''));

        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        try {
            $response = $request->post(AiProviderConfig::chatCompletionsUrl($baseUrl), [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'capability_probe',
                        'description' => 'Capability probe. Never call.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => ['probe' => ['type' => 'string']],
                            'required' => [],
                        ],
                    ],
                ]],
                'stream' => true,
                'max_tokens' => 1,
            ]);
        } catch (ConnectionException) {
            return ToolSupport::Unknown;
        }

        return $this->classifyResponse($response);
    }

    public function classifyResponse(Response $response): ToolSupport
    {
        if ($response->successful()) {
            return ToolSupport::Supported;
        }

        if ($response->status() >= 500) {
            return ToolSupport::Unknown;
        }

        return self::mentionsUnsupportedCapability($response->body())
            ? ToolSupport::Unsupported
            : ToolSupport::Unknown;
    }

    /**
     * Detect a provider that rejected the tool or streaming payload.
     */
    public static function indicatesUnsupportedTools(Throwable $exception): bool
    {
        [$status, $body] = self::describe($exception);

        if ($status === null || $status < 400 || $status >= 500) {
            return false;
        }

        return self::mentionsUnsupportedCapability($body);
    }

    /**
     * @return array{0: int|null, 1: string}
     */
    private static function describe(Throwable $exception): array
    {
        if ($exception instanceof PrismException) {
            return [
                $exception->httpStatus,
                (string) ($exception->responseBody ?? $exception->getMessage()),
            ];
        }

        if ($exception instanceof RequestException) {
            return [$exception->response->status(), $exception->response->body()];
        }

        return [null, ''];
    }

    private static function mentionsUnsupportedCapability(string $body): bool
    {
        $body = strtolower($body);

        $capabilities = ['tool', 'function_call', 'function call', 'streaming'];

        $signals = [
            'not supported',
            'unsupported',
            'does not support',
            "doesn't support",
            'no support',
            'not allowed',
            'not permitted',
            'not implemented',
            'invalid',
            'unknown',
            'unrecognized',
            'disabled',
        ];

        foreach ($capabilities as $capability) {
            if (! str_contains($body, $capability)) {
                continue;
            }

            foreach ($signals as $signal) {
                if (str_contains($body, $signal)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function cacheKey(array $config): string
    {
        return 'ai:tool-support:'.sha1(implode('|', [
            (string) ($config['provider'] ?? ''),
            (string) ($config['model'] ?? ''),
            (string) ($config['base_url'] ?? ''),
        ]));
    }
}
