#!/bin/sh
set -e

mkdir -p /data

# Only the SQLite driver needs a file; MySQL is its own service.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    touch "${DB_DATABASE:-/data/database.sqlite}"
fi

# The persisted key is the source of truth. Project passwords and AI API keys
# are encrypted with APP_KEY, so switching keys under an existing database
# would make every one of them undecryptable ("The MAC is invalid"). The
# environment variable is only used when nothing is persisted yet.
if [ -f /data/app.key ]; then
    APP_KEY="$(cat /data/app.key)"
    export APP_KEY
elif [ -n "$APP_KEY" ]; then
    printf '%s' "$APP_KEY" > /data/app.key
else
    php artisan key:generate --show > /data/app.key
    APP_KEY="$(cat /data/app.key)"
    export APP_KEY
fi

php artisan migrate --force

if [ "$SEED_DEMO" = "true" ] && [ ! -f /data/.seeded ]; then
    php artisan db:seed --force
    touch /data/.seeded
fi

# Metric collection, threshold alerts, approval expiry, and pruning.
php artisan schedule:work &

# Queued jobs: schema scans, and the metric collection the scheduler queues.
# One try, with the scan job's own 600 s limit, so a stuck scan is marked failed.
php artisan queue:work --tries=1 --timeout=600 &

# --no-reload is required for PHP_CLI_SERVER_WORKERS; without it artisan serve
# runs a single worker and one slow chat request blocks every other page.
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
