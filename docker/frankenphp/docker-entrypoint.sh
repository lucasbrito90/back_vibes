#!/bin/sh
set -e

cd /app

if [ -z "${APP_KEY:-}" ]; then
	echo "docker-entrypoint: APP_KEY must be set by the runtime environment (e.g. App Platform)." >&2
	exit 1
fi

# Discover packages (skipped during composer --no-scripts image build)
php artisan package:discover --ansi >/dev/null || true

# Safe with real runtime env from App Platform / orchestrator
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Present in Laravel 11+; harmless if unavailable
php artisan event:cache 2>/dev/null || true

# Octane worker mode (TD-5) — read by public/frankenphp-worker.php via
# vendor/laravel/octane/bin/frankenphp-worker.php. MAX_REQUESTS recycles
# each worker after N requests (leak safety net); LARAVEL_OCTANE marks the
# process as Octane-managed for any code/package that checks it.
export MAX_REQUESTS="${OCTANE_MAX_REQUESTS:-500}"
export LARAVEL_OCTANE=1

exec frankenphp run --config /etc/frankenphp/Caddyfile
