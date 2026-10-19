<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Models\AiSetting;
use App\Services\Ai\AiProviderConfig;
use Prism\Prism\Facades\Prism;

/**
 * Asks the configured AI provider which root cause explains a DBPA case.
 */
class LlmScenarioPredictor implements ScenarioPredictor
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, string>  $labels
     */
    public function predict(array $summary, array $labels): string
    {
        $config = AiProviderConfig::resolve(AiSetting::where('is_active', true)->first());

        $response = Prism::text()
            ->using($config['prism'], $config['model'], [
                'api_key' => $config['api_key'],
                'url' => $config['base_url'],
            ])
            ->withClientOptions(AiProviderConfig::clientOptions())
            ->withPrompt($this->prompt($summary, $labels))
            ->asText();

        return trim($response->text);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, string>  $labels
     */
    private function prompt(array $summary, array $labels): string
    {
        $lines = [];

        foreach ($summary['signals'] ?? [] as $signal) {
            $lines[] = sprintf(
                '- %s: %+.2f (normal %.2f, anomaly window %.2f)',
                $signal['key'],
                $signal['deviation'],
                $signal['baseline'],
                $signal['window_mean']
            );
        }

        $candidates = implode(', ', $labels);

        return implode("\n", [
            'You are a database performance expert. A workload shows these telemetry deviations from its Normal baseline (log2 ratio, 0 means unchanged, positive means higher than normal):',
            '',
            implode("\n", $lines),
            '',
            "Candidate root causes: {$candidates}",
            '',
            'Which single candidate best explains these deviations? Reply with exactly one candidate name and nothing else.',
        ]);
    }
}
