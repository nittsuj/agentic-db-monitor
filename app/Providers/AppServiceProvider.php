<?php

namespace App\Providers;

use App\Models\MetricAnalyzer;
use App\Models\MutationPlanner;
use App\Models\QueryDiagnostician;
use App\Services\Ai\AgentRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Outside production, reading a relation that was not eager-loaded
        // throws instead of quietly running one query per row (N+1). In
        // production it stays a normal lazy load, so a missed case is slow, not broken.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Short names in tool_execution_logs.loggable_type instead of class names.
        // morphMap rather than enforceMorphMap: models not listed here (User in
        // notifications) keep storing their class name exactly as before.
        Relation::morphMap([
            'query_diagnostician' => QueryDiagnostician::class,
            'metric_analyzer' => MetricAnalyzer::class,
            'mutation_planner' => MutationPlanner::class,
        ]);

        // Brute-force guard for token login: per email and client IP.
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        // Every chat call costs provider quota, so the limit is per token owner.
        RateLimiter::for('chat-ai', fn (Request $request) => Limit::perMinute((int) config('llm-hub.rate_limit'))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
