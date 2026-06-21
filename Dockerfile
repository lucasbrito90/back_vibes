# syntax=docker/dockerfile:1
# Ixora Laravel API — FrankenPHP on PHP 8.3 for DigitalOcean App Platform (http_port 8080).
# Laravel 11+ / 12 / 13 compatible (composer.json defines PHP ^8.3).

# ── Stage 1: Composer dependencies (no dev), optimized autoload ──────────────
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
	--no-dev \
	--no-scripts \
	--prefer-dist \
	--ignore-platform-reqs

COPY . .

RUN composer install \
	--no-dev \
	--no-scripts \
	--prefer-dist \
	--ignore-platform-reqs \
	&& composer dump-autoload --optimize --classmap-authoritative --no-dev

# ── Stage 2: FrankenPHP runtime ──────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4-bookworm AS app

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions

RUN chmod +x /usr/local/bin/install-php-extensions \
	&& install-php-extensions \
	pdo_pgsql \
	pgsql \
	mbstring \
	intl \
	bcmath \
	gd \
	zip \
	opcache

# Opcache tuned for container/FPM-style FrankenPHP worker lifecycle
ENV PHP_OPCACHE_ENABLE="1" \
	PHP_OPCACHE_MEMORY_CONSUMPTION="128" \
	PHP_OPCACHE_INTERNED_STRINGS_BUFFER="16" \
	PHP_OPCACHE_MAX_ACCELERATED_FILES="10000" \
	PHP_OPCACHE_VALIDATE_TIMESTAMPS="0"

WORKDIR /app

COPY --from=vendor --chown=www-data:www-data /app /app

COPY docker/frankenphp/conf.d/zz-uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
	&& mkdir -p \
	storage/framework/cache/data \
	storage/framework/sessions \
	storage/framework/views \
	storage/logs \
	bootstrap/cache \
	&& chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
