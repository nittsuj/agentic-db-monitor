<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

/**
 * The deviation formula shared by the benchmark and the dashboard: how far a
 * window is from its baseline, expressed as a log2 ratio.
 */
class MetricDeviation
{
    public static function log2Ratio(float $window, float $baseline): float
    {
        $epsilon = max(0.05 * max(abs($window), abs($baseline)), 1.0);

        return round(log((abs($window) + $epsilon) / (abs($baseline) + $epsilon), 2));
    }
}
