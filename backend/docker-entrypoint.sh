#!/bin/sh
set -e

# In production, verify configuration safety before starting or migrating
if [ "${APP_ENV}" = "production" ]; then
    echo "[Entrypoint] Validating production configuration safety..."
    if ! php artisan app:check-production-config; then
        echo "[Entrypoint] FATAL: Production configuration check failed! Refusing to start with unsafe defaults." >&2
        exit 1
    fi
fi

# Run database migrations if flag is explicitly enabled
if [ "${RUN_MIGRATIONS:-false}" = "true" ] || [ "${RUN_MIGRATIONS:-0}" = "1" ] || [ "$1" = "--migrate" ]; then
    if [ "$1" = "--migrate" ]; then
        shift
    fi
    echo "[Entrypoint] Running database migrations (--force)..."
    php artisan migrate --force
    echo "[Entrypoint] Applying audit table grants (--force)..."
    php artisan audit:grants --force

    # In development environment, run database seeders automatically
    if [ "${APP_ENV}" != "production" ] && { [ "${RUN_SEEDERS:-true}" = "true" ] || [ "${RUN_SEEDERS:-0}" = "1" ]; }; then
        echo "[Entrypoint] Running database seeders (--force)..."
        php artisan db:seed --force
    fi
fi

exec "$@"
