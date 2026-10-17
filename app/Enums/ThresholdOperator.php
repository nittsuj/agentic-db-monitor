<?php

declare(strict_types=1);

namespace App\Enums;

enum ThresholdOperator: string
{
    case Gt = 'gt';
    case Gte = 'gte';
    case Lt = 'lt';
    case Lte = 'lte';
    case Eq = 'eq';
    case Ne = 'ne';

    public function matches(float $sample, float $limit): bool
    {
        return match ($this) {
            self::Gt => $sample > $limit,
            self::Gte => $sample >= $limit,
            self::Lt => $sample < $limit,
            self::Lte => $sample <= $limit,
            self::Eq => $sample === $limit,
            self::Ne => $sample !== $limit,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Gt => '>',
            self::Gte => '≥',
            self::Lt => '<',
            self::Lte => '≤',
            self::Eq => '=',
            self::Ne => '≠',
        };
    }
}
