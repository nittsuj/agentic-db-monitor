<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

interface ScenarioPredictor
{
    /**
     * Name the root cause that best explains a case summary.
     *
     * @param  array<string, mixed>  $summary
     * @param  array<int, string>  $labels
     */
    public function predict(array $summary, array $labels): string;
}
