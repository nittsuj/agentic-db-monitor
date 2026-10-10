# Suggested Commands

Platform: Windows, PowerShell 5.1. Do NOT chain with `&&`; use `;` or `cmd1; if ($?) { cmd2 }`.

## App lifecycle
- Full setup: `composer setup` (install, copy .env, key:generate, migrate, npm install, npm run build)
- Dev (all processes): `composer dev` → runs `php artisan dev`
- List dev processes: `php artisan dev:list`
- Serve only: `php artisan serve`

## Database
- Migrate: `php artisan migrate`
- Fresh + seed: `php artisan migrate:fresh --seed`
- Re-seed demo data (idempotent, safe to repeat): `php artisan db:seed`
- Specific seeder: `php artisan db:seed --class=Database\Seeders\DemoSeeder`

## Frontend
- Dev server: `npm run dev`
- Production build: `npm run build`

## Tests / quality
- Run all tests: `composer test` (clears config then `php artisan test`)
- Direct: `php artisan test`
- Format: `vendor/bin/pint` (see `mem:task_completion`)

## Shell notes (Windows)
- Prefer full cmdlets: `Get-ChildItem`, `Get-Content`, `Remove-Item`, `Test-Path`.
- Run executables with spaces via call operator: `& "path/to/exe"`.
