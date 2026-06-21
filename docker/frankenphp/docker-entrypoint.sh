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

exec frankenphp run --config /etc/frankenphp/Caddyfile
