# Conventions

Conventions are now crystallized (not open questions). Full naming rules live in
`docs/naming_conventions.md`; schema/DDL/seeding in `docs/implementation_plan.md` §3 (gitignored).

- PHP: 4-space indent, PSR-12 / PSR-4. Types on params and return values, including `: void`
  on `register()`/`boot()` and controller actions. Method docblocks for `register`/`boot`.
- Namespaces follow directory layout under `App\` (e.g. `App\Providers`, `App\Models`,
  `App\Http\Controllers`).
- Controllers extend `App\Http\Controllers\Controller`; keep them thin.
- Routes defined in `routes/web.php` (web) and `routes/console.php` (console).
- DB access via Eloquent models + migrations in `database/migrations/`; factories under
  `database/factories/`.
- Migrations: `uuid('id')->primary()`; FKs `foreignUuid(...)->constrained()` with **explicit**
  `->cascadeOnDelete()` / `->nullOnDelete()` (`constrained()` adds no delete action).
- Direct inserts into uuid PKs must supply `Str::uuid7()` ids (no DB-side default).
- Idempotent seeding: key on natural unique keys AND merge those key columns into the insert;
  guard child collections with an `exists()` check; metric timestamps anchored to persisted rows,
  not run-time `now()`; no faker, no truncate (pattern: `DemoSeeder::ensureRow` / `ensureEmpty`).
- Frontend: Blade views in `resources/views`; Tailwind v4 utility classes; CSS config in
  `resources/css/app.css` via `@theme` (no `tailwind.config.js`).
- Formatting enforced with Laravel Pint.
- Do NOT add code comments unless necessary.
- Full naming conventions (PHP cases, tables/columns/FKs, migrations, enums, models,
  Livewire 3, docs-folder rules) live in `docs/naming_conventions.md` — UUID v7 PKs
  (`uuid('id')`/`foreignUuid`, `HasUuids`), enums PascalCase cases + lowercase values,
  component tags kebab-case, docs folders snake_case with identical basenames across
  diagram types.
