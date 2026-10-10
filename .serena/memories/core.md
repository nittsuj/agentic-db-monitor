# agentic-db-monitor — Core

AI-assisted database monitoring/troubleshooting app (Laravel backend + Blade/Tailwind frontend).
Purpose: let users save target DB credentials, chat with an AI to diagnose performance, run
read-only diagnostics automatically, gate write actions behind explicit user approval, send
desktop alerts on threshold breaches, and prune old telemetry.

## Status
Early-stage scaffold: default Laravel skeleton only (no domain code yet). Business requirements
live in `docs/user_story.md` (8 user stories: US-01..US-08, grouped into 3 modules).
Use case diagram: `docs/use_case_diagram.dio`.

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
