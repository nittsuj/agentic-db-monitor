<?php

declare(strict_types=1);

namespace App\Services\LlmHub;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A provider failure, already translated for the client. As an HttpException
 * it renders through the shared api/* error format with its own status code.
 */
final class LlmRequestFailedException extends HttpException
{
    public function __construct(
        int $statusCode,
        string $message,
        public readonly string $provider,
        public readonly string $model,
        ?Throwable $previous = null,
    ) {
        parent::__construct($statusCode, $message, $previous);
    }
}
