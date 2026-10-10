# Naming Conventions

Standardized naming across the project, by layer. The guiding principles are: follow PSR-1/PSR-4 and Laravel framework conventions where they exist, and keep filenames stable so links and diffs do not churn.

## 1. PHP code

| Item | Case | Example |
| --- | --- | --- |
| Classes / interfaces / enums / traits | StudlyCase (PascalCase) | `Project`, `Threshold`, `TargetConnectionManager` |
| Methods / functions | camelCase | `execute($statement)`, `prunable()` |
| Properties / variables | camelCase | `$projectId`, `$this->isActive` |
| Class constants | UPPER_SNAKE_CASE | `DbDriver::MYSQL`, `ActionStatus::PENDING` |
| Namespaces | `App\` + directory, PSR-4 | `App\Models\Project`, `App\Enums\DbDriver` |

Adopted unconditionally: PSR-1/PSR-4, matching Laravel and Pint, gives free autoloading, IDEs, and static analysis.

## 2. Database tables (local SQLite)

- Table names: **plural snake_case** — `projects`, `ai_settings`, `chat_sessions`, `chat_messages`, `pending_actions`, `action_logs`, `metric_samples`, `thresholds`.
- Eloquent derives a model's table via `Str::snake(Str::plural(class_basename($model)))`. Deviating means hardcoding `$table` and declaring it on every `constrained()`/`foreignUuidFor()` — avoid.
- No `tbl_` prefixes, no camel/kebab/singular names.

## 3. Columns

- **snake_case, no table-name echo** — `host`, `port`, `username`, `is_active`, `captured_at`, `executed_at`.
- Primary key: always `id` (UUID v7, `char(36)`).
- Foreign keys: `{singular_referenced_table}_id` — `project_id`, `chat_session_id`, `pending_action_id`.

## 4. Migrations

- Filename: `YYYY_MM_DD_HHMMSS_verb_table.php` — e.g. `2026_10_10_000001_create_projects_table.php`.
- One table per migration; `Schema::create('projects', ...)` uses the same table name as the file.
- Timestamp order decides dependency order; Laravel 13 parses the filename to prefill the generated stub.

## 5. Enums (`app/Enums/`, one enum per file)

| Element | Case | Example |
| --- | --- | --- |
| Enum class | StudlyCase | `DbDriver`, `ChatRole`, `ActionStatus`, `ThresholdOperator` |
| File | matches class | `app/Enums/ThresholdOperator.php` |
| Cases | PascalCase | `case Pending = 'pending'`, `case Gte = 'gte'` |
| Backed value (`->value`) | lowercase snake | `'mysql'`, `'pending'`, `'gt'`, `'dml'` |

- Cases are class constants (case-sensitive) — PascalCase matches the PHP manual and every style guide.
- Values persist to SQLite and travel through JSON/params; lowercase snake avoids case-comparison pitfalls and reads uniformly with sibling columns like `is_active`.
- No blanket `Enum`/`Type` suffix on the class name; name by domain.

## 6. Models, factories, relationships

- Models: singular StudlyCase in `App\Models\` — `Project`, `Threshold`, `AiSetting`, `MetricSample`.
- Factories: `Database\Factories\{Model}Factory` — `ProjectFactory`.
- Relationship methods: camelCase, named after the target model — `chatSessions()`, `pendingActions()`, `metricSamples()`.

## 7. Livewire 3 (pinned to v3 for Flux)

- Component class: StudlyCase in `App\Livewire\` — `App\Livewire\Chat\DiagnoseChat`, `App\Livewire\Settings\Thresholds`.
- Component tag: **kebab-case** (`<livewire:diagnose-chat />`); nesting via dots (`<livewire:settings.thresholds />`). StudlyCase tags are not recognized — hard framework requirement.
- View: `resources/views/livewire/...` snake_case — `chat/diagnose-chat.blade.php`.
- Do not adopt Livewire 4 single-file `⚡name.blade.php` components (v3 constraint).

## 8. Services / drivers / tools / jobs

- Nouns, StudlyCase: `TargetConnectionManager`, `PermissionChecker`, `MySqlDiagnosticDriver`, `PostgresDiagnosticDriver`, `ListTablesTool`, `CollectMetricsJob`.
- Folders snake_case (`app/Services/`, `app/Diagnostics/`, `app/Jobs/`).
- Do not suffix every class with `Service`; clarity comes from the namespace.

## 9. Remaining surfaces

| Surface | Case | Example |
| --- | --- | --- |
| Config files / keys | snake_case | `config/database.php`, `config('prism.provider')` |
| Env vars | SCREAMING_SNAKE_CASE | `DB_CONNECTION`, `OLLAMA_URL` (kept minimal — secrets live in the DB) |
| Routes / URIs | kebab-case | `/chat/{session}`, `/settings/thresholds` |
| Cache / queue keys | snake_case | `target.{project_id}.conn` |
| Job classes | StudlyCase | `CollectMetricsJob` |
| JSON `meta` keys | snake_case | `tool_name`, `tool_call_id` |

## 10. Docs folder file naming

```
docs/
  user_story.md            high_level_design.md
  implementation_plan.md   use_case_diagram.dio
  activity_diagram/    README.md  + 5 snake_case per-cluster files
  sequence_diagram/   README.md  + same 5 snake_case basenames as activity_diagram/
```

| Rule | Value | Examples |
| --- | --- | --- |
| Top-level filenames | snake_case | `user_story.md`, `high_level_design.md` |
| Subfolders | snake_case, singular noun | `activity_diagram/`, `sequence_diagram/` |
| Subfolder files | snake_case, no numeric prefixes, no version/draft suffixes | `ai_diagnostics.md`, not `01-ai-diag.md`/`-v2`/`_final` |
| `README.md` | the one uppercase exception | `activity_diagram/README.md` |
| Extensions | `.md` for markdown; native ext otherwise | `use_case_diagram.dio` |
| Cross-folder sync | identical basenames across diagram types for 1:1 links | `activity_diagram/ai_diagnostics.md` ↔ `sequence_diagram/ai_diagnostics.md` |

Rationale:

- **snake_case over kebab-case:** already established and referenced across the docs and Serena memories; switching would rewrite every relative link for no gain. Consistency beats a prettier separator.
- **Shared basenames across diagram folders:** keeps `../activity_diagram/…` links correct and makes "did I update both?" checks grep-able.
- **No numeric prefixes:** ordering lives inside the docs (UC numbers, phase numbers, index tables), so filenames stay stable; git diffs and cross-links don't churn.
- **No `_final`/`_draft`/`-v2`:** one source of truth per artifact; superseded docs are deleted, not suffixed (keeps the doc set free of stale leftovers).
- **README.md uppercase:** renderer-recognized landing page (GitHub/GitLab); free behavior.