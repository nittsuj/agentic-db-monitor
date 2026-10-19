<?php

declare(strict_types=1);

namespace App\Enums;

enum ToolSupport: string
{
    case Supported = 'supported';

    case Unsupported = 'unsupported';

    case Unknown = 'unknown';

    /**
     * Whether the agent may be offered diagnostic tools for this provider.
     */
    public function allowsTools(): bool
    {
        return $this !== self::Unsupported;
    }
}
