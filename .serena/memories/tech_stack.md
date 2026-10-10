# Tech Stack

## Backend — PHP / Laravel
- PHP `^8.3` (dev machine runs 8.5.5, NTS VS17 x64 build).
- `laravel/framework` `^13.17`; `laravel/tinker` `^3.0`.
- Composer 2.8.x; autoload PSR-4: `App\` → `app/`, `Database\Factories\`, `Database\Seeders\`.
- Default DB: SQLite (`DB_CONNECTION=sqlite`).

## Frontend — Vite / Tailwind
- Vite `^8`, `laravel-vite-plugin` `^3.1`, `@tailwindcss/vite` `^4`, `tailwindcss` `^4`.
- Entry points: `resources/css/app.css`, `resources/js/app.js` (see `vite.config.js`).
- Fonts: Bunny "Instrument Sans" (400/500/600) via vite plugin.
- Package manager: npm (`.npmrc` present); `type: module`.

## Dev / test tooling
- Tests: PHPUnit `^12.5` (`phpunit.xml`, suites Unit + Feature).
- Formatting: `laravel/pint` `^1.27`.
- Logs: `laravel/pail` `^1.2.5`.
- `nunomaduro/collision`, `mockery/mockery`, `fakerphp/faker`.
- Optional: `@laravel/multiplex` (npm optionalDependency).
