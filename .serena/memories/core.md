# agentic-db-monitor — Core

AI-assisted database monitoring/troubleshooting **desktop** app.
Purpose: save target DB credentials, chat with an AI to diagnose performance, run read-only
diagnostics automatically, gate write actions behind explicit per-write user approval, send
native desktop alerts on threshold breaches, and prune old telemetry.

## Status
Early-stage scaffold: default Laravel skeleton only (no domain code yet). Business requirements
live in `docs/user_story.md` (10 user stories: US-01..US-10, grouped into 4 modules).
Use case diagram: `docs/use_case_diagram.dio`.
Architecture topology + notes: `docs/high_level_design.md`.

## Locked decisions
- Single-user, local desktop app; no auth / no multi-tenancy.
- Runtime: NativePHP Desktop v2. UI: Livewire 3 + Flux UI (free) + Blade + Tailwind 4 + Vite.
- LLM: Prism PHP; default provider Ollama Cloud (free tier); API key encrypted at rest via Crypt.
- Local store: SQLite. Target databases: **MySQL and PostgreSQL only** (SQLite is not a target).
- Approval model: **per-write** — every mutating/DDL/maintenance action halts for explicit user
  approval; read-only tools run automatically.
- Threshold scope: per-project. Telemetry retention: raw samples pruned after 48h.

## Source map
- `app/` — PSR-4 `App\` (only `Http/Controllers/Controller.php`, `Models/User.php`, `Providers/AppServiceProvider.php`)
- `routes/web.php` — single `GET /` → `welcome` view
- `database/migrations/` — only default users/cache/jobs tables
- `resources/views/`, `resources/css/app.css`, `resources/js/app.js` — frontend
- `tests/{Unit,Feature}` — PHPUnit tests

## Invariants
- SQLite is the default DB (local: `database/database.sqlite`; tests: in-memory).
- Agent bootstrap instructions are duplicated in `AGENTS.md` and `CLAUDE.md`; `AGENTS.md` is the
  authoritative one after Laravel Boost is installed.

## Related memories
- Stack/frameworks/versions: `mem:tech_stack`
- Commands to build/run/test: `mem:suggested_commands`
- Code conventions: `mem:conventions`
- Definition of done: `mem:task_completion`
