# agentic-db-monitor — Core

AI-assisted database monitoring/troubleshooting **desktop** app.
Purpose: save target DB credentials, chat with an AI to diagnose performance, run read-only
diagnostics automatically, gate write actions behind explicit per-write user approval, send
native desktop alerts on threshold breaches, and prune old telemetry.

## Status
Architecture/planning is complete; Phase 1 (local store) is underway.
- Docs: `docs/user_story.md` (US-01..US-10, 4 modules), `docs/high_level_design.md`,
  `docs/entity_relationship_diagram.md` (Crow's foot), `docs/naming_conventions.md`,
  `docs/sequence_diagram/` + `docs/activity_diagram/` (5 flows each, validated against mermaid).
- Master plan: `docs/implementation_plan.md` — **gitignored by design**; schema DDL §3.1–3.8,
  phases §4, demo seeding §3.7/3.8. Read it before touching data-layer code.
- Done: 8 domain migrations (`2026_10_10_000001`..`000008`), `DatabaseSeeder` → `DemoSeeder`
  (idempotent demo data + demo login `admin@example.com` / `password`).
- Pending: 8 Eloquent models + factories, 6 enums, schema tests (§3.2–3.4 of the plan).

## Locked decisions
- Single-user, local desktop app; no auth / no multi-tenancy. Demo account is seed-only.
- Runtime: NativePHP Desktop v2. UI: Livewire 3 + Flux UI (free) + Blade + Tailwind 4 + Vite.
- LLM: Prism PHP; default provider Ollama Cloud (free tier); API key encrypted at rest via Crypt.
- Local store: SQLite. Target databases: **MySQL and PostgreSQL only** (SQLite is not a target).
- Approval model: **per-write** — every mutating/DDL/maintenance action halts for explicit user
  approval; read-only tools run automatically.
- Threshold scope: per-project. Telemetry retention: raw samples pruned after 48h.
- **UUID v7 PKs** (`HasUuids` → `Str::uuid7()`); `uuid('id')` columns have NO DB-side default, so
  direct inserts must supply the id.
- **FK delete actions are always explicit** — `constrained()` adds none. Cascade for
  chat/metrics/threshold/pending; `nullOnDelete` everywhere `action_logs` is touched plus
  `pending_actions.chat_session_id` (audit preserved via denormalized `project_name`/`engine`).
- Enums persist as lowercase strings + Eloquent casts (never native ENUM — SQLite).
- `metric_samples.value` numeric-only; context in `meta` json; 48h `Prunable`.

## Source map
- `app/` — only `Models/User.php`, `Http/Controllers/Controller.php`, `Providers/AppServiceProvider.php`.
- `routes/web.php` — single `GET /` → `welcome`.
- `database/migrations/` — 3 defaults + 8 domain (`2026_10_10_*`).
- `database/seeders/` — `DatabaseSeeder` → `DemoSeeder` (idempotency helpers `ensureRow`/`ensureEmpty`).
- `docs/` — plan + diagrams (see Status).

## Invariants
- SQLite is the default DB (local: `database/database.sqlite`; tests: in-memory).
- Demo seeding must stay idempotent: `firstOrCreate`/`ensureRow` keyed on natural unique keys (key
  columns included in the insert); metric series anchored to the project's persisted `created_at`,
  NEVER run-time `now()` (that was the duplicate-samples bug); no faker, no truncate (§3.8).
- Agent bootstrap instructions are duplicated in `AGENTS.md` and `CLAUDE.md`; `AGENTS.md` is the
  authoritative one after Laravel Boost is installed.

## Related memories
- Stack/frameworks/versions: `mem:tech_stack`
- Commands to build/run/test: `mem:suggested_commands`
- Code conventions: `mem:conventions`
- Definition of done: `mem:task_completion`
