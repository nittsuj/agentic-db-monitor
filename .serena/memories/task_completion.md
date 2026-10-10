# Task Completion

Run these after making code changes (Windows PowerShell; don't chain with `&&`):

1. Format: `vendor/bin/pint` (or `vendor/bin/pint --dirty` to format only changed files).
2. Tests: `php artisan test` (or `composer test`, which clears config first).
   - Add `--filter=<name>` for targeted runs.
3. If frontend assets changed: `npm run build` to confirm they compile.

No separate type-checker/linter is configured beyond Pint and PHPUnit.
