<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

## Project: Agentic DB Monitor

Local, single-user desktop-style Laravel app for AI-assisted MySQL/PostgreSQL monitoring. Read-only diagnostics run automatically; every write is queued for explicit human approval.

### Commands

```bash
composer setup                      # install deps, .env, key, migrate, npm build
composer dev                        # runs `php artisan dev` (laravel/pao: server + vite + queue + logs)
php artisan db:seed                 # DemoSeeder: demo projects, metrics, chat, pending approvals
php artisan schedule:work           # collector (5 min), approval expiry (10 min), prune (hourly)
php artisan dbpa:import --project=<id> --scenario=normal   # replay DBPA CSV samples
php artisan dbpa:benchmark --engine=pgsql --config=32_128   # score the agent against the DBPA labels
php artisan dbpa:recommend-thresholds --engine=pgsql --config=32_128   # default threshold per metric
php artisan telemetry:clear         # delete samples outside the 48-hour window
composer test                       # config:clear + php artisan test
php artisan test --compact --filter=testName
php artisan test tests/Unit/StatementClassifierTest.php
vendor/bin/pint --dirty --format agent
npm run build                       # when Blade/Tailwind changes don't appear
```

Tests run PHPUnit (not Pest) against in-memory SQLite. Livewire components are tested with `Livewire::test()` in `tests/Feature`.

### Two databases — keep them straight

- **App database** (`DB_CONNECTION=sqlite`): the app's own tables — projects, chat, approvals, metrics, thresholds. Reached normally via Eloquent.
- **Target databases**: the user's monitored MySQL/Postgres instances. Never configured in `config/database.php`. `TargetConnectionManager::connect()` registers a runtime connection named `target_{project_id}` from the `Project` row, and callers **must** `purge()` it in a `finally` block (see `ChatWindow::send`, `MutationExecutor::execute`). `connectTemporary()` is the 5s-timeout variant used only for credential verification.

All app models use `HasUuids` (UUID v7 `char(36)` primary keys). The "active project" is session state: `session('active_project_id')`, set by the `switch-project` route.

### Write-approval invariant

The AI never executes a mutating statement. The chain is:

1. `ChatWindow::send` builds the tool set: read-only tools from `DiagnosticTools::for($connection, $driver)` plus `ProposeMutationTool::make($project, $chatSessionId)`. Tool handlers return JSON **strings** — Prism rejects array returns, so keep the `json_encode` contract when adding a tool.
2. `propose_mutation` rejects SQL containing more than one statement (`StatementClassifier::containsMultipleStatements`, which ignores semicolons inside strings, identifiers, comments, and dollar-quoted blocks), then passes it through `StatementClassifier` (regex → `ActionCategory::Dml|Ddl|Maintenance`, `null` = read-only). `null` is rejected back to the model; otherwise a `PendingAction` row is created with `status = Pending` and the model is told to inform the user, not retry.
3. `ApprovalQueue::approve` flips status to `Approved`, calls `MutationExecutor`, then sets `Executed` or `Failed`. `MutationExecutor` re-checks for multiple statements before connecting, as defense in depth.
4. Every decision (`Executed` / `Failed` / `Denied`) writes an immutable `ActionLog` row. `ActionLog` denormalizes `project_name` and `engine` so history survives project deletion — tested by `ActionLogPreservationTest`.
5. The queue only lists and decides actions for `session('active_project_id')`; pending rows older than `PendingAction::EXPIRES_AFTER_HOURS` (24) are marked `Expired`. Approve/deny appends a `tool` `ChatMessage` to the originating session so the next turn sees the outcome.

When adding a statement type or engine, update `StatementClassifier` patterns and the matching test; do not widen what reaches `MutationExecutor`.

### AI layer (Prism)

`prism-php/prism` drives the chat. Provider config is a DB row, not env: `AiSetting` where `is_active = true`, with `api_key` cast `encrypted` (decryption of a corrupt value is caught and degraded to `''`). `AiProviderConfig` centralizes provider defaults and model allow-lists; with no active row the app defaults to the ITS LLM gateway (`openai_compatible`, `https://senopati.its.ac.id/llm-gateway/v1`, `qwen3:14b`, served by Prism's DeepSeek driver). Ollama (`llama3.1`) and OpenAI (`gpt-4.1-mini`) remain selectable. Requests set an explicit connect/response timeout and failures go through `AiErrorTranslator` (401/403/404/429/5xx/network). Responses stream via `asStream()` + Livewire `$this->stream('assistantContent', ...)`, and the Blade view needs `wire:stream` for tokens to appear live. `HiddenTagStreamFilter` strips `<think>…</think>` blocks from the live stream, including tags split across deltas. History is the last 20 user/assistant/tool `ChatMessage` rows; tool (approval outcome) rows are replayed as `UserMessage` prefixed `[tool result]`.

Not every provider accepts function calling (FR-27). `ToolSupportVerifier` probes the endpoint at most once an hour and caches the verdict. `ChatWindow::send` sends no tools and warns the user when the verdict is `Unsupported`, and retries once without tools when the provider rejects the tool payload mid-request. The telemetry of the active project reaches the agent through the `get_metric_summary` tool (`MetricSummaryService`), which reads the app's own `metric_samples`, never the target database.

### Engine-specific diagnostics

Per-engine SQL lives behind the `DiagnosticDriver` interface (`MySqlDiagnosticDriver`, `PostgresDiagnosticDriver`), selected by `DiagnosticTools::driverFor($engine)`. Adding a diagnostic means: a method on the interface, both drivers, and a `Tool` in `DiagnosticTools::for`. `collectMetrics()` is the non-tool method used by the scheduler: it returns `connection_pool_used` and `slow_queries_per_min` gauges (MySQL derives the rate from `Slow_queries`/`Uptime`; Postgres counts `pg_stat_statements` rows averaging over 1s and treats a missing extension as zero). `PermissionChecker` probes the same surfaces at onboarding (MySQL: `information_schema`, `PROCESS`, `performance_schema` digest; Postgres: `pg_catalog`, `pg_stat_activity`, `pg_stat_statements`) and returns a `PermissionCheckResult` splitting hard failures from warnings.

### Metrics & retention

`MetricSample` is `Prunable` with a 48-hour window; `HealthDashboard` reads ranges of 5/15/60/360/1440/2880 minutes (`#[Url] minutes`, `#[Lazy]`, `#[Computed]`) through `MetricSeriesService`: ranges up to 15 minutes return raw samples, longer ranges are averaged per minute (max for `locks`, `lock_waiting_sessions`, `long_running_queries`). `anomalySignals()` returns the five largest log2 deviations (`MetricDeviation`, shared with the benchmark) between anomalous and Normal samples. KPI cards for metrics the active engine does not report are hidden. Samples are joined against enabled `Threshold` rows to flag breaches; charts are rendered by the `Dashboard\MetricChart` view component.

### UI shell

The layout (`components/layouts/app.blade.php`) is hand-written, not `flux:sidebar`: the design system lives in `resources/css/app.css` (`--adbm-*` tokens, dark default, classes `app/side/top/nav/card/btn/input/badge`), icons in the `components/icons` SVG sprite, and `resources/js/app.js` handles the mobile drawer and the theme toggle (re-bound on `livewire:navigated`). Pages fill `<x-slot:heading>`, `subtitle`, and `actions`. `Shell\Sidebar` owns the project switcher (`GET /switch-project/{project}`), the approvals badge, the provider box, and "Pulihkan data demo" (re-runs the idempotent `DemoSeeder`). `Projects\ProjectManager` (FR-05) edits or deletes a project; deletion cascades to chat, metrics, thresholds, and pending actions but keeps `ActionLog`. `AiSettingsForm::testConnection()` is wired to the "Tes koneksi" button.

`CollectMetrics` (queued, scheduled every five minutes) uses `MetricCollector` to connect to every `is_active` project, writes the samples, then calls `ThresholdEvaluator`. The evaluator sends a `ThresholdBreached` database notification to existing users at most once per threshold per 15-minute cache cooldown; failures are logged per project and do not stop the loop. `routes/console.php` also schedules `approvals:expire` and `model:prune`; `telemetry:clear` is the manual retention command. `MetricKey` lists the metric keys shared by the collector, the DBPA importer, and threshold validation. `dbpa:import` reads `database/dbpa/csv/<engine>/<config>/<scenario>.csv` (generated by `database/dbpa/convert_dbpa.py`), deletes all of the project's previous samples (live and replay are never mixed, FR-37), stamps replay timestamps backwards from now in 5-second steps, and prepends a Normal case before each anomalous case unless `--no-normal` is passed.

`dbpa:benchmark` (FR-33) hands the agent the per-case metric deviations of a labelled scenario and compares its answer with the label; the accuracy per scenario is stored in `benchmark_reports` (`BenchmarkReport`). `dbpa:recommend-thresholds` (FR-34) compares the Normal distribution with the anomalous one per metric and only recommends a threshold when the two are separable. Both read the same CSV files through `DbpaCsvReader`; the Laravel app never opens a DBPA pickle.

### Docs & skills

`docs/` holds the design of record — `high_level_design.md`, `entity_relationship_diagram.md`, `naming_conventions.md`, `user_story.md`, plus per-flow `activity_diagram/` and `sequence_diagram/` pairs (onboarding, ai_diagnostics, per_write_approval, monitoring_and_retention, configuration). Read the matching flow doc before changing one of those paths. Project skills are in `.agents/skills/` (there is no `.ai/rules/` directory in this repo, despite the Boost rule above). `AGENTS.md` is a byte-identical copy of this file — update both together.
