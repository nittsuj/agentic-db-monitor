<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\PrivilegeLevel;

final class PermissionCheckResult
{
    /**
     * @param  list<string>  $passedChecks
     * @param  list<string>  $warnings  Missing monitoring capabilities (extensions, grants).
     * @param  list<string>  $privilegeNotes  Why the user was classified at its privilege level.
     */
    public function __construct(
        public bool $successful,
        public ?string $errorMessage = null,
        public array $passedChecks = [],
        public array $warnings = [],
        public PrivilegeLevel $privilegeLevel = PrivilegeLevel::Unknown,
        public array $privilegeNotes = [],
        public ?string $currentUser = null,
    ) {}

    /**
     * @param  list<string>  $passedChecks
     * @param  list<string>  $warnings
     * @param  list<string>  $privilegeNotes
     */
    public static function success(
        array $passedChecks = [],
        array $warnings = [],
        PrivilegeLevel $privilegeLevel = PrivilegeLevel::Unknown,
        array $privilegeNotes = [],
        ?string $currentUser = null,
    ): self {
        return new self(
            successful: true,
            errorMessage: null,
            passedChecks: $passedChecks,
            warnings: $warnings,
            privilegeLevel: $privilegeLevel,
            privilegeNotes: $privilegeNotes,
            currentUser: $currentUser,
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

    /**
     * Anything the user should see before the profile is saved.
     */
    public function needsReview(): bool
    {
        return $this->warnings !== [] || $this->privilegeLevel === PrivilegeLevel::Admin;
    }
}
