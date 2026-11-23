<?php

declare(strict_types=1);

namespace App\Services\LlmHub;

/**
 * A finished LLM reply. Token counts are null when the provider sent no usage.
 */
final readonly class LlmResult
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $totalTokens = null,
    ) {}
}
