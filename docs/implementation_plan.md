# Agentic DB Monitor — Implementation Plan

AI-assisted database monitoring and troubleshooting desktop app. Users register
target databases (MySQL / PostgreSQL), then chat with an agentic LLM that runs
read-only diagnostics automatically and must obtain explicit per-write approval
before any mutation. Background workers poll health metrics, raise native OS
alerts, and prune old telemetry.

- **App type:** single-user, local desktop app.
- **Runtime:** NativePHP Desktop v2 (Electron) + PHP.
- **UI:** Livewire 3 + Flux UI (free) + Blade + Tailwind CSS 4 + Vite.
- **LLM:** Prism PHP, default provider Ollama Cloud (free tier), key encrypted at rest.
- **Local store:** SQLite.
- **Target databases:** MySQL and PostgreSQL.

---

## Progress Status (as of 2026-10-17)

| Phase | Title | Status | Pint | Tests |
| --- | --- | --- | --- | --- |
| 0 | Foundations | ✅ Complete | ✅ | ✅ |
| 1 | Local store & credentials | ✅ Complete | ✅ | ✅ |
| 2 | AI diagnostics core | 🔄 In progress | — | — |
| 3 | Guardrails: per-write approval | ⏳ Not started | — | — |
| 4 | Background monitoring & alerts | ⏳ Not started | — | — |
| 5 | Retention | ⏳ Not started | — | — |
| 6 | Definition of Done / Packaging | ⏳ Not started | — | — |

**Current branch:** `main` · **Test suite:** 58 tests / 147 assertions · all green.

---

## 1. Locked Decisions

| Decision | Choice |
| --- | --- |
| Distribution model | Single-user, local desktop (native), no auth/multi-tenancy |
| Desktop runtime | NativePHP Desktop v2.x (`nativephp/desktop`) |
| UI stack | Livewire 3 (streaming via `$this->stream()`), Flux UI (free), Blade, Tailwind 4, Vite |
| LLM integration | `prism-php/prism` |
| Default provider | Ollama Cloud free tier (`OLLAMA_URL=https://ollama.com`) with `Authorization: Bearer` API key |
| Provider fallback | Ollama OpenAI-compatible endpoint (`https://ollama.com/v1`) via Prism OpenAI provider, only if hard tool-choice is required |
| API key storage | Encrypted in local SQLite via `Crypt` (not `.env`, distributable desktop app) |
| Tool-call enforcement | Prompt-enforced "list-first" (Ollama has no tool-choice/required-tools) |
| Approval model | **Per-write**: every mutating/DDL/maintenance action halts for explicit user approval; read-only tools run automatically |
| Threshold scope | **Per-project** (FK to `Project`), evaluated only within that workspace |
| Telemetry retention | Automatic pruning of raw samples older than 48h |

---

## 2. Architecture Overview

### 2.1 Runtime topology

```
NativePHP (Electron) window
  └─ loopback HTTP server (Laravel)
       ├─ Livewire components (chat, sidebar, onboarding, settings)
       ├─ Prism (agent loop: tools + streaming tokens)
       └─ NativePHP QueueWorker  (background metrics + pruning)
            └─ Laravel Scheduler (every-5-min poll, daily/houlry prune)
```

### 2.2 Key components

- **`TargetConnectionManager`** — decrypts a `Project`'s credentials, builds a
  runtime Laravel connection config, and registers it under a deterministic name
  (e.g. `target_{project_id}`). All diagnostic tools receive this connection;
  code must never use the default `DB::` connection for target queries.
- **`PermissionChecker`** — per-engine probe that verifies the account can read
  the required telemetry views before a `Project` is saved. Returns actionable
  errors (never a broken saved profile).
- **`DiagnosticDriver`** — per-engine abstraction (`MySqlDiagnosticDriver`,
  `PostgresDiagnosticDriver`) that backs the fixed diagnostic tools.
- **Sandboxed tools** — a fixed, parameterized inventory. The agent can only
  invoke these; raw SQL generated from natural language is never executed.
- **`PendingAction`** — persisted record of a proposed mutation awaiting
  approval, enabling the halt/resume agent loop.
- **`ActionLog`** — audit trail of proposed, approved, executed, and denied
  actions.

### 2.3 Engine divergence

| Concern | PostgreSQL | MySQL |
| --- | --- | --- |
| Slow query source | `pg_stat_statements` | `performance_schema.events_statements_summary_by_digest` |
| Activity / pool | `pg_stat_activity` | `SHOW PROCESSLIST` / `information_schema.PROCESSLIST` |
| Schema fetch | `information_schema` / `pg_catalog` | `information_schema` |
| Bloat analysis | `pgstattuple` / statistics estimates | `information_schema.TABLES` (data_free) |
| Maintenance candidate | `VACUUM` | `OPTIMIZE TABLE` |
| Required privileges | `pg_read_all_stats` / `pg_monitor` | `PROCESS`, `REPLICATION CLIENT`, `SELECT` on `performance_schema` |

---

## 3. Data Model (local SQLite)

| Model | Purpose | Notable fields |
| --- | --- | --- |
| `Project` | Target DB profile | `driver` (mysql/pgsql), host, port, database, username, `password` (encrypted cast), options (json), `is_active` |
| `AiSetting` | Provider config (UC-09) | provider (default `ollama`), model, base_url, `api_key` (encrypted cast), `is_active` |
| `ChatSession` | Per-project chat thread | `project_id` (FK) |
| `ChatMessage` | Message history | `chat_session_id` (FK), role, content, meta (json) |
| `MetricSample` | Raw background telemetry | `project_id` (FK), metric key, value (double), captured_at |
| `Threshold` | Per-project alert rule | `project_id` (FK), metric key, operator, value, enabled |
| `PendingAction` | Mutation awaiting approval | `project_id`, `chat_session_id`, exact command, engine, category, status |
| `ActionLog` | Audit trail | `project_id`, `project_name` (denormalized), `pending_action_id`, decision, executed_at, actor |

- Primary keys are **UUID v7** (`use HasUuids`, which generates `Str::uuid7()` in Laravel 13);
  columns are `uuid('id')->primary()` and foreign keys are `foreignUuid`.
- `Threshold`, `MetricSample`, `ChatSession`/`ChatMessage`, and `PendingAction` cascade-delete with
  `Project`. `PendingAction.chat_session_id` is `nullOnDelete`.
- **`ActionLog` is preserved on project delete**: `project_id` is `nullOnDelete` and the audit row
  keeps a denormalized `project_name`/`engine` so it stays readable after the project is gone
  (append-only audit trail; optional later audit-prune).
- `MetricSample` uses the `Prunable` contract (48h cutoff). Its `value` is numeric-only;
  contextual detail lives in the `meta` json column.
- `AiSetting` stores provider config; the API key is encrypted at rest via `Crypt`, and `model` is
  validated against a code-side allow-list of tool-capable models.
- Backed enums (`App\Enums`): `DbDriver`, `ChatRole`, `ActionCategory`, `ActionStatus`,
  `ActionDecision`, `ThresholdOperator`.

### 3.1 Migrations (`database/migrations/2026_10_10_*`)

All PKs `uuid('id')->primary()`; every FK is `foreignUuid(...)->constrained()->…` with an
explicit delete action (`constrained()` adds none by default).

| Migration | Table | Columns & actions |
| --- | --- | --- |
| `000001_create_projects_table` | `projects` | name, driver, host, port (usSmallInt), database, username, password (text), options (json, null), is_active (bool, default true). Unique `(driver,host,port,database,username)`; index `is_active` |
| `000002_create_ai_settings_table` | `ai_settings` | provider (default `ollama`), model (null), base_url (null), api_key (null), is_active (default true) |
| `000003_create_chat_sessions_table` | `chat_sessions` | project_id → `cascadeOnDelete`; title (null) |
| `000004_create_chat_messages_table` | `chat_messages` | chat_session_id → `cascadeOnDelete`; role; content (longText, null); meta (json, null). Index `(chat_session_id, created_at)` |
| `000005_create_pending_actions_table` | `pending_actions` | project_id → `cascadeOnDelete`; chat_session_id (null) → `nullOnDelete`; engine; category; command (text); status (default `pending`); meta (json, null); proposed_at; decided_at / executed_at (null). Index `(project_id, status)` |
| `000006_create_action_logs_table` | `action_logs` | project_id (null) → `nullOnDelete`; project_name (denormalized); engine; pending_action_id (null) → `nullOnDelete`; chat_session_id (null) → `nullOnDelete`; decision; command (text); actor (default `user`); result / error (null); executed_at (null). Index `(project_id, created_at)` |
| `000007_create_metric_samples_table` | `metric_samples` | project_id → `cascadeOnDelete`; key; value (double); captured_at; meta (json, null). Index `(project_id, key, captured_at)` |
| `000008_create_thresholds_table` | `thresholds` | project_id → `cascadeOnDelete`; key; operator; value (double); enabled (default true) |

### 3.2 Enums (`app/Enums`, lowercase backed values)

| Enum | Cases (`value`) |
| --- | --- |
| `DbDriver` | `Mysql` (`mysql`), `Pgsql` (`pgsql`); `defaultPort(): int` → 3306 / 5432 |
| `ChatRole` | `User` (`user`), `Assistant` (`assistant`), `Tool` (`tool`), `System` (`system`) |
| `ActionCategory` | `Ddl` (`ddl`), `Dml` (`dml`), `Maintenance` (`maintenance`) |
| `ActionStatus` | `Pending` (`pending`), `Approved` (`approved`), `Denied` (`denied`), `Executed` (`executed`), `Failed` (`failed`), `Expired` (`expired`) |
| `ActionDecision` | `Approved` (`approved`), `Denied` (`denied`), `Executed` (`executed`), `Failed` (`failed`) |
| `ThresholdOperator` | `Gt` (`gt`), `Gte` (`gte`), `Lt` (`lt`), `Lte` (`lte`), `Eq` (`eq`), `Ne` (`ne`) |

### 3.3 Models & factories

- Every model uses `HasFactory` + `HasUuids` and an explicit `$fillable` array.
- `Project`: casts `driver: DbDriver`, `password: encrypted`, `options: array`,
  `is_active: bool`; relations `chatSessions()`, `pendingActions()`, `actionLogs()`,
  `metricSamples()`, `thresholds()`.
- `AiSetting`: casts `api_key: encrypted`, `is_active: bool`; helper `configured()`.
- `ChatSession`: `belongsTo Project`; `hasMany ChatMessage`.
- `ChatMessage`: `belongsTo ChatSession`; casts `role: ChatRole`, `meta: array`.
- `PendingAction`: `belongsTo Project`, `belongsTo ChatSession` (nullable); casts
  `category: ActionCategory`, `status: ActionStatus`, `meta: array`, datetimes.
- `ActionLog`: `belongsTo Project`/`ChatSession`/`PendingAction` (nullable); casts
  `decision: ActionDecision`; `fillDenormalized()` stamps `project_name` + `engine`.
- `MetricSample`: `use Prunable`; `prunable()` → `captured_at < now() - 48h`; casts
  `value: float`, `captured_at: datetime`, `meta: array`.
- `Threshold`: `belongsTo Project`; casts `operator: ThresholdOperator`,
  `value: float`, `enabled: bool`.
- Factories (`database/factories/`) for all 8 models with plaintext defaults; the
  `encrypted` casts run on save, so tests assert the round-trip.

### 3.4 Schema tests

- `tests/Feature/DataModelSchemaTest.php` (structural): tables/columns/indexes exist
  post-migrate; FK delete actions correct; UUID v7 PK format on save; unique project
  constraint; enum casts persist lowercase and reject invalid values (`ValueError`);
  encrypted cast round-trip for `password`/`api_key`.
- `tests/Feature/ActionLogPreservationTest.php`: project delete keeps `action_logs`
  (`project_id` null, `project_name`/`engine` intact); `pending_actions.chat_session_id` nulls.
- `tests/Feature/MetricSamplePrunableTest.php`: `pruneAll()` removes 49h-old samples,
  keeps fresh ones.
- `tests/Unit/EnumsTest.php`: full case/value sets for all six enums, incl.
  `DbDriver::defaultPort()`.

### 3.5 Canonical migration bodies (DDL reference)

Ordered parent-before-child; the `2026_10_10_000001…000008` prefix fixes both up- and
rollback order (Laravel rolls back in reverse). Every `down()` is
`Schema::dropIfExists($table)`. Column types are cross-DB (app SQLite store + MySQL +
PostgreSQL share one migration). Enum fields are lowercase `string` columns behind Eloquent
casts — no native `ENUM`/check constraints (absent on SQLite, painful to alter later).

Standard file shape:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // body below
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
```

Bodies:

```php
// 000001_create_projects_table.php
Schema::create('projects', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('driver');                       // DbDriver: 'mysql'|'pgsql'
    $table->string('host');
    $table->unsignedSmallInteger('port');
    $table->string('database');
    $table->string('username');
    $table->text('password');                       // encrypted cast
    $table->json('options')->nullable();            // array cast
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['driver', 'host', 'port', 'database', 'username']);
    $table->index('is_active');
});
```

```php
// 000002_create_ai_settings_table.php
Schema::create('ai_settings', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('provider')->default('ollama');
    $table->string('model')->nullable();
    $table->string('base_url')->nullable();
    $table->text('api_key')->nullable();            // encrypted cast
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

```php
// 000003_create_chat_sessions_table.php
Schema::create('chat_sessions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
    $table->string('title')->nullable();
    $table->timestamps();
});
```

```php
// 000004_create_chat_messages_table.php
Schema::create('chat_messages', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('chat_session_id')->constrained()->cascadeOnDelete();
    $table->string('role');                         // ChatRole
    $table->longText('content')->nullable();
    $table->json('meta')->nullable();               // array cast
    $table->timestamps();

    $table->index(['chat_session_id', 'created_at']);
});
```

```php
// 000005_create_pending_actions_table.php
Schema::create('pending_actions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('chat_session_id')->nullable()->constrained()->nullOnDelete();
    $table->string('engine');
    $table->string('category');                     // ActionCategory
    $table->text('command');
    $table->string('status')->default('pending');   // ActionStatus
    $table->json('meta')->nullable();               // array cast
    $table->timestamp('proposed_at');
    $table->timestamp('decided_at')->nullable();
    $table->timestamp('executed_at')->nullable();
    $table->timestamps();

    $table->index(['project_id', 'status']);
});
```

```php
// 000006_create_action_logs_table.php
Schema::create('action_logs', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
    $table->string('project_name');                 // denormalized snapshot at write time
    $table->string('engine');
    $table->foreignUuid('pending_action_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignUuid('chat_session_id')->nullable()->constrained()->nullOnDelete();
    $table->string('decision');                     // ActionDecision
    $table->text('command');
    $table->string('actor')->default('user');
    $table->text('result')->nullable();
    $table->text('error')->nullable();
    $table->timestamp('executed_at')->nullable();
    $table->timestamps();

    $table->index(['project_id', 'created_at']);
});
```

```php
// 000007_create_metric_samples_table.php
Schema::create('metric_samples', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
    $table->string('key');
    $table->double('value');                        // numeric-only; context lives in meta
    $table->timestamp('captured_at');
    $table->json('meta')->nullable();
    $table->timestamps();

    $table->index(['project_id', 'key', 'captured_at']);
});
```

```php
// 000008_create_thresholds_table.php
Schema::create('thresholds', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
    $table->string('key');
    $table->string('operator');                     // ThresholdOperator
    $table->double('value');
    $table->boolean('enabled')->default(true);
    $table->timestamps();
});
```

Delete actions mirror the ERD: cascade for chat/metrics/threshold/pending; `nullOnDelete`
everywhere `action_logs` is touched, so the audit trail survives project deletion.

### 3.6 Indexes & query paths

FK columns are already indexed by `constrained()`. Composite indexes serve the hot paths;
enum casts let predicates use the stored lowercase value directly
(e.g. `PendingAction::where('status', ActionStatus::Pending)`).

| Index | Serves |
| --- | --- |
| unique `(driver, host, port, database, username)` | duplicate-profile guard (+ lookup) |
| `(project_id, status)` | approval worklist |
| `(chat_session_id, created_at)` | chat thread render + ordering |
| `(project_id, key, captured_at)` | metric series, graphs, and 48h prune range |
| `(project_id, created_at)` | audit browse + retention window |

### 3.7 Demo data & seeding

**Goal:** idempotent demo data so the app shows content on first run, plus a demo login account.

- **Files:** `database/seeders/DemoSeeder.php` (new), wired into `DatabaseSeeder` so a plain
  `php artisan db:seed` / `migrate --seed` produces it. The default `test@example.com` block is
  replaced by the demo account.
- **Idempotency:** every insert uses `firstOrCreate` keyed on natural unique keys — projects on
  `(driver, host, port, database, username)`, user on `email`, metric samples on
  `(project_id, key, captured_at)`; collection children (messages, logs, pending actions) are
  guarded by an `exists()` check. Re-runs add zero rows and never throw unique/duplication
  errors; no `truncate`/`refresh`.
- **Content (seed order is FK-safe):**
  - User `admin@example.com` / `password` (hashed via `Hash::make`), name `Demo Admin`.
  - Two project profiles with distinct unique tuples: MySQL `127.0.0.1:3306` / `playground` /
    `root` and Postgres `127.0.0.1:5432` / `playground_pg` / `postgres`; `password` stored via
    `Crypt::encryptString('secret')` so it forwards into the future `encrypted` cast.
  - One active `AiSetting`: `ollama` / `llama3.1` / `https://ollama.com` / `ollama-demo-key`.
  - One `ChatSession` per project with 4 messages each (user → assistant → tool result →
    assistant), `meta` json populated.
  - `PendingAction`: one pending (`OPTIMIZE TABLE events;`) and one executed historical row.
  - Four `ActionLog` rows with denormalized `project_name` / `engine`, mixed decisions,
    `executed_at`, and result/error text.
  - `MetricSample`: 2 keys (`connection_pool_used`, `slow_queries_per_min`) × 2 projects ×
    96 points (48h @ 30 min); deterministic sinusoidal values with spikes so some breach
    thresholds. Fields stay numeric-only; context lives in `meta`.
  - `Threshold`: 3 per project — pool > 80 (enabled), slow queries > 5 (enabled), pool > 90
    (disabled).
- **Determinism:** no faker randomness; values are hand-authored or computed from the loop
  index, so re-seeding and verification are stable.
- **Models-free:** the seeder uses DB facades + `Crypt` and stores lowercase enum strings, so it
  stays valid once the Eloquent model layer and casts land.
- **Exit check:** `php artisan db:seed` run twice; second run adds zero rows; Pint + tests pass.

### 3.8 Seeder implementation

Wiring: `DatabaseSeeder::run()` calls `$this->call(DemoSeeder::class)`; the default
`test@example.com` block is removed (demo account is the single seeded user).

`database/seeders/DemoSeeder.php` is **models-free** (DB facade + `Crypt` + `Str::uuid7()`):
the 8 app tables have no Eloquent models yet, so rows go in via `DB::table(...)`; `users` does
have a model and uses `User::firstOrCreate()`. Lowercase enum strings and
`Crypt::encryptString` values are stored directly, so the data is forward-compatible with the
model casts that land later.

Idempotency helpers (the core of "no duplication error"):

```php
private function ensureRow(string $table, array $key, array $data): string
{
    $existing = DB::table($table)->where($key)->value('id');

    if ($existing !== null) {
        return (string) $existing;
    }

    $id = (string) Str::uuid7();

    DB::table($table)->insert(array_merge(['id' => $id], $key, $data)); // key columns are inserted too

    return $id;
}

private function ensureEmpty(string $table, array $where, Closure $generator): void
{
    if (DB::table($table)->where($where)->exists()) {
        return;
    }

    $generator();
}
```

The `$key` columns are merged into the insert (not just the `where`) — omitting that fails
`NOT NULL` on SQLite. `uuid7()` ids are mandatory — each `uuid('id')` PK has no DB-side
default, so SQLite inserts must supply the id. One `$now = now()` is captured up front so all
timestamps are stable across runs.

Sections run in FK-safe order:
1. **Users** — `User::firstOrCreate(['email' => 'admin@example.com'], ['name' => 'Demo Admin',
   'password' => Hash::make('password'), 'email_verified_at' => $now])`.
2. **Projects (2)** — `ensureRow('projects', ['driver','host','port','database','username'], …)`
   for the MySQL `127.0.0.1:3306` and Postgres `127.0.0.1:5432` tuples; data = `name`,
   `Crypt::encryptString('secret')`, `options` json, `is_active`, timestamps; project ids are
   returned and reused by later blocks.
3. **AiSetting** — `ensureRow('ai_settings', ['provider' => 'ollama', 'model' => 'llama3.1'], …)`
   with `base_url`, `ollama-demo-key`, `is_active`.
4. **Chat sessions** — one per project, keyed `['project_id' => $id, 'title' => 'Playground chat']`.
5. **Chat messages** — `ensureEmpty` on the session, then 4 fixed rows (roles `user`,
   `assistant`, `tool`, `assistant`); the tool row's `meta` carries e.g.
   `['tool' => 'check_connection_pool']`.
6. **Pending actions** — `ensureEmpty('pending_actions', ['project_id' => $mysqlId])`: one
   `pending`/`maintenance` `OPTIMIZE TABLE events;` (`proposed_at`) plus one executed
   `ANALYZE TABLE events;` with staggered `proposed_at`/`decided_at`/`executed_at`.
7. **Action logs** — `ensureEmpty(...)`: 4 rows covering `approved`+`executed` (with `result`),
   `denied` (`executed_at` null), and `failed` (with `error`); denormalized `project_name` and
   `engine` stamped from the owning project row.
8. **Metric samples** — deterministic 96-point series per project per key, **anchored to each
   project's persisted `created_at`** (not the run-time `now()`), so back-to-back and later
   re-seeds reproduce the exact same `captured_at` values and never duplicate rows:

```php
$projectCreatedAt = DB::table('projects')->where('id', $projectId)->value('created_at');
$start = Carbon::parse($projectCreatedAt)->subHours(48);
for ($i = 0; $i < 96; $i++) {
    $capturedAt = $start->copy()->addMinutes($i * 30);
    $pool = round(45 + 30 * sin($i / 9) + ($i % 23 === 0 ? 35 : 0), 1);    // breaches >80
    $slow = round(max(0, 4 + 3 * sin($i / 7) + ($i % 17 === 0 ? 6 : 0)), 1); // breaches >5
    // ensureRow('metric_samples', ['project_id', 'key', 'captured_at'], …) for each project/key
}
```
   `value` stays numeric-only; `meta` = `['source' => 'demo-seeder']`. Anchoring to the stored
   `created_at` keeps the whole series run-stable; keying re-runs off a fresh `now()` was the
   bug that caused duplicate samples.

9. **Thresholds** — per project: `connection_pool_used > 80` enabled, `slow_queries_per_min > 5`
   enabled, `connection_pool_used > 90` disabled; keyed `['project_id', 'key', 'operator']`.

Determinism: no `fake()` anywhere — values are hand-authored or computed from `$i`. Feedback:
one `$this->command->info(...)` line with the seeded counts.

Exit verification: `php artisan db:seed` run twice → identical row counts (users 1, projects 2,
ai_settings 1, chat_sessions 2, chat_messages 8, pending_actions 2, action_logs 4,
metric_samples 384, thresholds 6); Pint + `php artisan test` green.

See also: [`entity_relationship_diagram.md`](./entity_relationship_diagram.md).

---

## 4. Phases

Each phase ends with Pint + tests passing and is independently demoable.

### Phase 0 — Foundations ✅ Complete

**Goal:** install and wire the desktop + UI + LLM stack.

Tasks:
- ✅ Install `nativephp/desktop` (v2.x), run `php artisan native:install`.
- ✅ Install `livewire/livewire` (3), `livewire/flux` (free) with `php artisan flux:install`, and `prism-php/prism`.
- ✅ Run `php artisan boost:install` and reconcile `AGENTS.md` with Boost guidelines.
- ✅ Publish `config/prism.php`; add `ollama` block with `api_key` and `url` sourced
  from the encrypted settings layer (see Phase 1), not raw `.env`, for the
  distributed app.
- ✅ Define a tool-capable model allow-list for the model picker.

Exit criteria:
- ✅ `php artisan native:dev` opens the app window.
- ✅ Prism can reach Ollama Cloud with a test key (streaming smoke test).

### Phase 1 — Local store & credentials (US-01, US-02) 🔄 In progress (~75%)

Tasks:
- ✅ Migrations + models per §3.1–3.3: `Project`, `AiSetting`, `ChatSession`, `ChatMessage`,
  `MetricSample`, `Threshold`, `PendingAction`, `ActionLog` (UUID v7 PKs via `HasUuids`;
  explicit `->cascadeOnDelete()` / `->nullOnDelete()` on every FK; `$fillable`; the six
  `App\Enums` incl. `DbDriver::defaultPort()`).
- ✅ Demo seeder per §3.7: `DemoSeeder` wired into `DatabaseSeeder` (idempotent; demo account
  `admin@example.com` / `password`; 384 metric samples, 6 thresholds, pending actions,
  action logs).
- ✅ Schema tests per §3.4: `DataModelSchemaTest`, `ActionLogPreservationTest`,
  `MetricSamplePrunableTest`, `EnumsTest` — written and passing.
- ✅ Encrypted `password` cast on `Project` (Crypt). `api_key` encrypted on `AiSetting`.
- ✅ `TargetConnectionManager` (`app/Services/Database/TargetConnectionManager.php`):
  decrypt + register runtime connection; `connect(Project)`, `connectTemporary(…)`,
  `purge(string)`. Tested by `tests/Unit/TargetConnectionManagerTest.php`.
- ✅ `PermissionChecker` (`app/Services/Database/PermissionChecker.php`): per-engine
  probe (MySQL: `information_schema`, `SHOW PROCESSLIST`, `performance_schema` digest;
  PostgreSQL: `pg_catalog`, `pg_stat_activity`, `pg_stat_statements`). Returns
  `PermissionCheckResult` with `passedChecks` and `warnings`.
- ✅ `CreateProjectModal` (`app/Livewire/Projects/CreateProjectModal.php`): Livewire
  component + Flux modal form; driver auto-fills default port and username; runs
  `PermissionChecker` on submit; blocks save on failure with inline callout; saves
  `Project`, sets `session('active_project_id')`, redirects to dashboard.
  Tested by `tests/Feature/Livewire/CreateProjectModalTest.php`.
- ✅ Sidebar workspace switcher: lists projects in sidebar with active state; `+` icon
  and `Add Database` button both trigger `CreateProjectModal` via `flux:modal.trigger`.
  Temporary `GET /switch-project/{project}` route for browser session switching.
- ✅ Health Dashboard (`HealthDashboard` Livewire + Flux UI): full-page component at
  `/dashboard`; SVG sparklines (`MetricChart`); breach callout; range switcher (1h/6h/24h/48h);
  pending approvals table; recent audit log. Tested by
  `tests/Feature/Livewire/HealthDashboardTest.php` (16 cases).
- ✅ Threshold settings UI (US-10): `ThresholdManager` Livewire component + view +
  `/thresholds` route; per-project scoped CRUD, enable/disable toggle.
- ✅ AI provider settings UI (US-09): `AiSettingsForm` Livewire component + view +
  `/settings/ai` route; encrypted key handling, allow-list validation.

Exit criteria:
- ✅ Invalid profiles are rejected with an actionable message (no broken save).
- ✅ Switching workspaces isolates chat/metrics/tools.
- ✅ `/dashboard` renders scoped telemetry, trends, pending actions, and audit logs.
- ✅ Valid MySQL and PostgreSQL profiles save after passing the permission check
  (verified end-to-end against live MySQL 8 and Postgres 16: PermissionChecker passes
  for both; TargetConnectionManager connects to both seeded profiles).

### Phase 2 — AI diagnostics core (US-03, US-04, US-05)

Tasks:
- `DiagnosticDriver` interface + `MySqlDiagnosticDriver` and
  `PostgresDiagnosticDriver`.
- Fixed sandboxed tools bound to the active project's connection:
  `ListTablesTool`, `GetTableSchemaTool`, `GetSlowQueriesTool`,
  `CheckConnectionPoolTool`, `AnalyzeTableBloatTool`.
- System prompt: list-first discovery; `GetTableSchemaTool` only for known
  tables; hard cap of 2–3 schema lookups per prompt context.
- Chat Livewire component: persist user message, run Prism `->asStream()` tool
  loop, stream tokens via `$this->stream()`, persist assistant message/tool
  results per `ChatSession`.
- Read-only tools execute automatically on LLM invocation; results feed back
  into the Prism context.

Exit criteria:
- Plain-language questions return streamed analyses backed by tool calls.
- No raw/unstructured SQL executes against the target.
- Schema lookups respect the 2–3 cap per context.

### Phase 3 — Guardrails: per-write approval (US-06)

**Design spike first** (highest-risk item).

Tasks:
- Statement classifier: read-only vs mutating/DDL/maintenance (per engine).
- Mutating tools create a `PendingAction`, return control, and **halt** the loop
  (do **not** execute).
- Confirmation modal shows the **exact command string**.
  - **Approve** → execute against the target, append the tool result, resume the
    Prism loop.
  - **Deny** → append a cancellation notice to the Prism context so the agent is
    informed and can adapt.
- `ActionLog` records proposed / approved / denied / executed for every action.
- Every mutation requires approval — no session-level bypass.

Exit criteria:
- `OPTIMIZE TABLE` / `CREATE INDEX` / `VACUUM` (and equivalents) never run
  without an explicit approve action.
- Denial informs the agent without executing anything.
- Full audit trail available.

### Phase 4 — Background monitoring & alerts (US-07, US-10)

Tasks:
- `CollectMetrics` scheduled job (every 5 minutes) writing `MetricSample`.
- Per-project threshold evaluation; on breach, fire a native OS notification via
  the NativePHP Notification API.
- NativePHP `QueueWorker` configured with `--tries=1`.
- Set the failed-jobs driver to `null` so jobs are discarded (no `failed_jobs`
  bloat); suppress stack traces for failed external DB connections.
- Target connections use explicit timeouts.
- Threshold settings UI (US-10): per-project CRUD, enable/disable.

Exit criteria:
- Metric polling runs on schedule; anomalies raise OS notifications.
- Failed target connections do not accumulate failed jobs.
- Thresholds are scoped strictly to their project.

### Phase 5 — Retention (US-08)

Tasks:
- Implement `Prunable` on `MetricSample` (cutoff 48 hours).
- Schedule `php artisan model:prune`.
- (Optional) manual "clear logs" action.

Exit criteria:
- Samples older than 48h are purged automatically.
- `Project` and other config records are never affected by pruning.

### Phase 6 — Definition of Done

- Format: `vendor/bin/pint`.
- Tests: `php artisan test` (feature + unit; `Prism::fake()` and Livewire
  component tests; SQLite in-memory for the local store).
- Build: `php artisan native:build` for the target OS.
- Update `AGENTS.md` with any Boost-generated guidelines.

---

## 5. Risks & Mitigations

| Risk | Impact | Mitigation |
| --- | --- | --- |
| US-06 mid-loop pause/resume is hard | High | Build the `PendingAction` halt/resume as a dedicated spike before Phase 2 tool loop is finalized |
| `pg_stat_statements` needs superuser + restart | Medium | Detect-and-instruct only; never attempt to create the extension |
| MySQL `performance_schema` statement cap / missing grants | Medium | Probe + actionable error in Phase 1; document required grants |
| Ollama lacks tool-choice/required-tools | Medium | Prompt-enforced list-first; keep OpenAI-compat route as documented fallback |
| Ollama no native JSON mode | Low | Treat Prism structured output as best-effort; handle `PrismException` |
| NativePHP scheduler + queue worker co-existence | Medium | Verify v2 runs the Laravel scheduler alongside the `QueueWorker` early in Phase 4 |
| SSE streaming over the native webview | Low | Loopback HTTP server supports SSE; smoke-test in Phase 0 |

---

## 6. Traceability Matrix

| User Story | Phase | Status | Primary artifacts |
| --- | --- | --- | --- |
| US-01 Configure Database Credentials | 1 | ✅ Done | `Project` ✅, `TargetConnectionManager` ✅, `PermissionChecker` ✅, `CreateProjectModal` ✅, AI settings UI ✅ |
| US-02 View Project Workspace | 1 | ✅ Done | Sidebar switcher ✅, `/switch-project` route ✅, `HealthDashboard` ✅ |
| US-03 Troubleshoot Database via Chat | 2 | ⏳ Not started | Chat Livewire component, Prism `->asStream()` |
| US-04 Inspect Table Schemas | 2 | ⏳ Not started | `ListTablesTool`, `GetTableSchemaTool`, system prompt caps |
| US-05 Execute Authorized AI Diagnostics | 2 | ⏳ Not started | `DiagnosticDriver`, read-only tools |
| US-06 Authorize Modifying Actions | 3 | ⏳ Not started | `PendingAction`, confirmation modal, `ActionLog` |
| US-07 Receive Performance Alerts | 1/4 | 🔄 Partial | Dashboard breach display ✅; `CollectMetrics` job ⏳, NativePHP Notification ⏳ |
| US-08 Clear Old Telemetry Logs | 5 | ⏳ Not started | `Prunable` `MetricSample`, `model:prune` |
| US-09 Configure AI Provider | 0/1 | ✅ Done | `AiSetting` model ✅, encrypted key ✅, settings UI ✅ |
| US-10 Configure Alert Thresholds | 1/4 | 🔄 Partial | Dashboard breach display ✅, `Threshold` model ✅, settings CRUD UI ✅; `CollectMetrics` ⏳ |

---

## 7. Definition of Done (per task)

1. `vendor/bin/pint` passes.
2. `php artisan test` passes (new behavior covered by tests).
3. No raw SQL from the LLM reaches a target database.
4. Every mutation path is gated by per-write approval and audited.
