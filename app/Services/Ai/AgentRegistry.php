<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\MetricAnalyzer;
use App\Models\MutationPlanner;
use App\Models\QueryDiagnostician;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Which agent ran a chat tool, so its execution log can point to it.
 *
 * Each agent's own row (by slug) is loaded once per request and its handles
 * list decides the tools. A tool no agent handles is an error, so a new tool
 * cannot be added to the chat without saying which agent it belongs to.
 */
class AgentRegistry
{
    /** @var list<class-string<QueryDiagnostician|MetricAnalyzer|MutationPlanner>> */
    public const AGENTS = [
        QueryDiagnostician::class,
        MetricAnalyzer::class,
        MutationPlanner::class,
    ];

    /** @var array<string, Model>|null tool name => agent */
    private ?array $byTool = null;

    public function forTool(string $tool): Model
    {
        $this->byTool ??= $this->load();

        return $this->byTool[$tool]
            ?? throw new RuntimeException("No agent handles the chat tool '{$tool}'. Add it to an agent's handles list.");
    }

    /**
     * @return array<string, Model>
     */
    private function load(): array
    {
        $byTool = [];

        foreach (self::AGENTS as $class) {
            $agent = $class::query()->where('slug', $class::SLUG)->first()
                ?? throw new RuntimeException("The {$class} row (slug '".$class::SLUG."') is missing. Run the migrations.");

            foreach ($agent->handles as $tool) {
                $byTool[$tool] = $agent;
            }
        }

        return $byTool;
    }
}
