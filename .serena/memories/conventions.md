# Conventions

Standard Laravel conventions; no custom deviations established yet (project is an early scaffold).

- PHP: 4-space indent, PSR-12 / PSR-4. Types on params and return values, including `: void`
  on `register()`/`boot()` and controller actions. Method docblocks for `register`/`boot`.
- Namespaces follow directory layout under `App\` (e.g. `App\Providers`, `App\Models`,
  `App\Http\Controllers`).
- Controllers extend `App\Http\Controllers\Controller`; keep them thin.
- Routes defined in `routes/web.php` (web) and `routes/console.php` (console).
- DB access via Eloquent models + migrations in `database/migrations/`; factories under
  `database/factories/`.
- Frontend: Blade views in `resources/views`; Tailwind v4 utility classes; CSS config in
  `resources/css/app.css` via `@theme` (no `tailwind.config.js`).
- Formatting enforced with Laravel Pint.
- Do NOT add code comments unless necessary.
