<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Contracts\Encryption\DecryptException;

final class AiProviderConfig
{
    public const OPENAI_COMPATIBLE = 'openai_compatible';

    public const DEFAULT_PROVIDER = self::OPENAI_COMPATIBLE;

    public const TIMEOUT_SECONDS = 45;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * @var array<string, array{
     *     base_url: string,
     *     model: string,
     *     prism: string,
     *     api_key_required: bool,
     *     models: array<int, string>|null
     * }>
     */
    private const PROVIDERS = [
        'ollama' => [
            'base_url' => 'https://ollama.com',
            'model' => 'llama3.1',
            'prism' => 'ollama',
            'api_key_required' => false,
            'models' => null,
        ],
        'openai' => [
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4.1-mini',
            'prism' => 'openai',
            'api_key_required' => true,
            'models' => null,
        ],
        self::OPENAI_COMPATIBLE => [
            'base_url' => 'https://senopati.its.ac.id/llm-gateway/v1',
            'model' => 'qwen3:14b',
            'prism' => 'deepseek',
            'api_key_required' => true,
            'models' => ['qwen3:14b'],
        ],
    ];

    /**
     * @return array<int, string>
     */
    public static function providers(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function isValidProvider(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    /**
     * @return array{base_url: string, model: string}
     */
    public static function defaults(string $provider): array
    {
        $config = self::PROVIDERS[$provider] ?? self::PROVIDERS[self::DEFAULT_PROVIDER];

        return [
            'base_url' => $config['base_url'],
            'model' => $config['model'],
        ];
    }

    public static function apiKeyRequired(string $provider): bool
    {
        return self::PROVIDERS[$provider]['api_key_required'] ?? true;
    }

    /**
     * @return array<int, string>|null
     */
    public static function allowedModels(string $provider): ?array
    {
        return self::PROVIDERS[$provider]['models'] ?? null;
    }

    /**
     * Prism provider driver that speaks the provider's API. The OpenAI driver
     * uses the Responses API, while generic gateways implement plain
     * /chat/completions, so openai_compatible is served by Prism's DeepSeek driver.
     */
    public static function prismProvider(string $provider): string
    {
        return self::PROVIDERS[$provider]['prism'] ?? self::PROVIDERS[self::DEFAULT_PROVIDER]['prism'];
    }

    /**
     * @return array{provider: string, model: string, base_url: string, prism: string, api_key: string}
     */
    public static function resolve(?AiSetting $setting): array
    {
        $configured = $setting !== null
            && is_string($setting->provider)
            && self::isValidProvider($setting->provider);
        $provider = $configured ? $setting->provider : self::DEFAULT_PROVIDER;
        $defaults = self::defaults($provider);

        return [
            'provider' => $provider,
            'model' => $configured && self::isFilled($setting->model) ? $setting->model : $defaults['model'],
            'base_url' => $configured && self::isFilled($setting->base_url) ? $setting->base_url : $defaults['base_url'],
            'prism' => self::prismProvider($provider),
            'api_key' => self::apiKey($setting),
        ];
    }

    public static function apiKey(?AiSetting $setting): string
    {
        if ($setting === null) {
            return '';
        }

        try {
            return (string) ($setting->api_key ?? '');
        } catch (DecryptException) {
            return '';
        }
    }

    public static function chatCompletionsUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/').'/chat/completions';
    }

    /**
     * @return array<string, int>
     */
    public static function clientOptions(): array
    {
        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
        ];
    }

    private static function isFilled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
