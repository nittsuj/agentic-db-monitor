# Agentic DB Monitor

AI-assisted database monitoring and troubleshooting as a local desktop app. Chat in plain English to diagnose MySQL/PostgreSQL performance issues, let the assistant run read-only diagnostic checks automatically, and gate every write action behind explicit per-write approval.

## How it works

- **Chat** (`/chat`): ask about the active project's database. The assistant (via Prism) calls read-only diagnostic tools on its own: list tables, table schema, slow queries, connection pool, table bloat, and the telemetry summary of the active project. If the configured provider rejects function calling, the chat drops to a tool-free mode and says so.
- **Writes need approval**: the assistant can only *propose* a change. It is queued as Pending and runs only after you approve it in **Approvals** (`/approvals`). Executed, failed, and denied actions are all recorded in the action log. Proposals with more than one statement are rejected, and pending proposals expire after 24 hours.
- **Dashboard** (`/dashboard`): a KPI row, metric charts over 5m/15m/1h/6h/24h/48h, threshold breach flags (managed under **Thresholds**, `/thresholds`), and a source badge (Live or DBPA replay). For replay data a panel lists the five signals that deviate most between the anomaly and the Normal window.
- **Projects**: the sidebar switches the active project. **Kelola** edits a project's connection (an empty password keeps the stored one) or deletes it together with its chat, metrics, thresholds, and pending proposals; the audit log is kept.
- **Settings** (`/settings/ai`): choose the AI provider. The ITS LLM gateway (`https://senopati.its.ac.id/llm-gateway/v1`, model `qwen3:14b`) is the default; Ollama and OpenAI are still available. The API key is encrypted with `APP_KEY`. **Tes koneksi** checks the endpoint and reports whether it supports function calling.
- **Monitoring**: a scheduled collector records `connection_pool_used` and `slow_queries_per_min` for every active project every five minutes, evaluates enabled thresholds, and stores one database notification per breach per 15-minute cooldown.
- **Retention**: metric samples older than 48 hours are pruned hourly; `php artisan telemetry:clear` clears them on demand.

The app stores its own data in SQLite. The databases you monitor are MySQL or PostgreSQL servers, connected only while a request needs them.

## Scheduled jobs

`php artisan schedule:work` runs these; the Docker image starts it next to the web server.

| Command | Cadence | What it does |
| --- | --- | --- |
| `CollectMetrics` (job) | every 5 minutes | Connects to every active project, writes a metric sample per metric, then evaluates the project's thresholds. A failing project is logged and does not stop the other projects. |
| `approvals:expire` | every 10 minutes | Marks pending proposals older than 24 hours as `expired`. |
| `model:prune` | hourly | Deletes metric samples older than 48 hours. Configuration and the audit log are never touched. |

## Artisan commands

| Command | Purpose |
| --- | --- |
| `dbpa:import` | Loads DBPA CSV samples into a project as replay data. |
| `dbpa:benchmark` | Scores the agent against the labelled DBPA scenarios and stores an accuracy report per scenario. |
| `dbpa:recommend-thresholds` | Derives a default threshold per metric from the Normal and anomaly distributions of the dataset. |
| `telemetry:clear` | Deletes metric samples outside the 48-hour window. |
| `approvals:expire` | Expires stale pending proposals. |

## Option 1: Docker (no local PHP needed)

Requires Docker Desktop running.

```bash
docker compose up --build
```

Then open <http://localhost:8000>. To load demo data once (sample projects, metrics, chat history, pending approvals):

```powershell
$env:SEED_DEMO="true"; docker compose up --build   # PowerShell
SEED_DEMO=true docker compose up --build           # bash
```

Notes:
- The app is published on `127.0.0.1` only; it is a single-user local tool, not a network service.
- App data and the generated app key live in the `app-data` Docker volume. Reset everything with `docker compose down -v`.
- The key persisted in that volume always wins over an `APP_KEY` environment variable. Project passwords and API keys are encrypted with it, so switching keys under an existing database would make them undecryptable.
- The container runs `php artisan schedule:work` next to the web server, so collection, alerts, approval expiry, and pruning run automatically.
- From inside the container, a service on your own machine is `host.docker.internal`, not `localhost`. Use it for a local Ollama (`http://host.docker.internal:11434`) and for the host of a monitored database.

## Option 2: Local setup

Requirements:

- PHP 8.4 or newer (the locked dependencies need it; `composer.json` alone says ^8.3)
- Composer
- Node.js + npm

```bash
composer setup
php artisan db:seed    # optional demo data
composer dev
```

`composer setup` installs dependencies, creates `.env`, generates an app key, runs migrations, and builds frontend assets. `composer dev` starts the dev server.

The scheduler is not part of `composer dev`; run it in a second terminal:

```bash
php artisan schedule:work
```

## DBPA replay data (optional)

The dashboard can replay the public DBPA benchmark (`Huang et al., SIGMOD 2023`) instead of waiting for live samples:

```bash
python3 database/dbpa/convert_dbpa.py --dataset DBPA_dataset   # once, after cloning DBPA_dataset
php artisan dbpa:import --project=<project-id> --engine=pgsql --config=32_128 --scenario=lock_waits
```

Each anomalous case is preceded by a Normal segment unless `--no-normal` is passed. Re-importing replaces that project's previous telemetry, so live and replay data are never mixed in one chart, and the replay ends at the current time so the samples fall inside the 48-hour window.

The dashboard plots one card per metric key it finds, so a replay of the dataset shows all ten of its metrics (the dataset has no slow-query gauge — that one is collected live).

In Docker the CSV files are mounted read-only instead of baked into the image, so the import runs inside the container:

```bash
docker compose exec app php artisan dbpa:import --project=<project-id> --engine=pgsql --config=32_128 --scenario=lock_waits --path=/dbpa/csv
```

Two more commands work on the same dataset:

```bash
# How often does the agent name the right root cause?
php artisan dbpa:benchmark --engine=pgsql --config=32_128 --cases=3

# Which default thresholds separate Normal from anomalous traffic?
php artisan dbpa:recommend-thresholds --engine=pgsql --config=32_128
php artisan dbpa:recommend-thresholds --engine=pgsql --config=32_128 --project=<project-id> --apply
```

`dbpa:benchmark` hands the agent the metric deviations of a case and compares its answer with the scenario label; the accuracy per scenario is stored in the `benchmark_reports` table. `dbpa:recommend-thresholds` compares the Normal distribution with the anomalous one and only recommends a threshold when the two are actually separable.

## Tests

```bash
composer test
php artisan test --compact --filter=testName
```

## Docs

Design documents are in `docs/`: high-level design, ERD, user stories, naming conventions, and per-flow activity and sequence diagrams.
