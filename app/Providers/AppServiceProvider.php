<?php

namespace App\Providers;

use App\Models\MetricAnalyzer;
use App\Models\MutationPlanner;
use App\Models\QueryDiagnostician;
use App\Services\Ai\AgentRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request (or queued job), so agents are read once, not per tool call.
        $this->app->scoped(AgentRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short names in tool_execution_logs.loggable_type instead of class names.
        // morphMap rather than enforceMorphMap: models not listed here (User in
        // notifications) keep storing their class name exactly as before.
        Relation::morphMap([
            'query_diagnostician' => QueryDiagnostician::class,
            'metric_analyzer' => MetricAnalyzer::class,
            'mutation_planner' => MutationPlanner::class,
        ]);
    }
}
