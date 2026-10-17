<?php

declare(strict_types=1);

namespace App\Services\Database;

final class PermissionCheckResult
{
    /**
     * @param  list<string>  $passedChecks
     * @param  list<string>  $warnings
     */
    public function __construct(
        public bool $successful,
        public ?string $errorMessage = null,
        public array $passedChecks = [],
        public array $warnings = [],
    ) {}

    /**
     * @param  list<string>  $passedChecks
     * @param  list<string>  $warnings
     */
    public static function success(array $passedChecks = [], array $warnings = []): self
    {
        return new self(
            successful: true,
            errorMessage: null,
            passedChecks: $passedChecks,
            warnings: $warnings,
        );
    }

    /**
     * @param  list<string>  $passedChecks
     * @param  list<string>  $warnings
     */
    public static function failure(string $errorMessage, array $passedChecks = [], array $warnings = []): self
    {
        return new self(
            successful: false,
            errorMessage: $errorMessage,
            passedChecks: $passedChecks,
            warnings: $warnings,
        );
    }
}
