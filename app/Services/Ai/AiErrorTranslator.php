<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Throwable;

final class AiErrorTranslator
{
    public function translate(Throwable $exception): string
    {
        $status = $this->status($exception);
        $message = strtolower($exception->getMessage());

        return match (true) {
            $exception instanceof PrismRateLimitedException, $status === 429 => 'The AI provider is rate limiting requests right now. Wait a moment and try again.',
            $exception instanceof PrismProviderOverloadedException, $status !== null && $status >= 500 => 'The AI provider is temporarily unavailable. Try again in a few minutes.',
            $status === 401 => 'The AI provider rejected the API key. Check the key in AI settings.',
            $status === 403 => 'The AI provider refused this request. Check that your account can use the configured model.',
            $status === 404 => 'The configured model or endpoint was not found. Check the model name and base URL in AI settings.',
            $exception instanceof ConnectionException, str_contains($message, 'timed out') => 'Could not reach the AI provider. Check the base URL and your network connection, then try again.',
            default => 'The assistant could not complete that request. Check your AI provider settings and database connection, then try again.',
        };
    }

    private function status(Throwable $exception): ?int
    {
        if ($exception instanceof PrismException) {
            if ($exception->httpStatus !== null) {
                return $exception->httpStatus;
            }

            $code = $exception->getCode();

            return is_int($code) && $code >= 400 && $code < 600 ? $code : null;
        }

        return null;
    }
}
