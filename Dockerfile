# syntax=docker/dockerfile:1
# Ixora Laravel API — FrankenPHP on PHP 8.4 for DigitalOcean App Platform (http_port 8080).
# Laravel 11+ / 12 / 13 compatible.

# ── Stage 1: Composer dependencies (no dev) ─────────────────────────────────
# Composer scripts are intentionally disabled in this stage because the
# OpenTelemetry native PHP extension is installed only in the runtime stage.
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
	--no-dev \
	--no-scripts \
	--prefer-dist \
	--no-interaction \
	--ignore-platform-reqs

COPY . .

RUN composer install \
	--no-dev \
	--no-scripts \
	--prefer-dist \
	--no-interaction \
	--ignore-platform-reqs \
	&& composer dump-autoload \
		--optimize \
		--classmap-authoritative \
		--no-dev \
		--no-scripts

# ── Stage 2: FrankenPHP runtime ──────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4-bookworm AS app

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer

RUN chmod +x /usr/local/bin/install-php-extensions /usr/local/bin/composer \
	&& install-php-extensions \
	pdo_pgsql \
	pgsql \
	mbstring \
	intl \
	bcmath \
	gd \
	zip \
	opcache \
	opentelemetry \
	&& php --ri opentelemetry

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

# Run Composer scripts only after the OpenTelemetry extension is loaded.
# This allows Laravel package discovery and OpenTelemetry auto-instrumentation
# registration to complete successfully during the image build.
RUN php --ri opentelemetry \
	&& composer check-platform-reqs --no-dev \
	&& composer dump-autoload \
		--optimize \
		--classmap-authoritative \
		--no-dev \
	&& chmod +x /usr/local/bin/docker-entrypoint.sh \
	&& mkdir -p \
		storage/framework/cache/data \
		storage/framework/sessions \
		storage/framework/views \
		storage/logs \
		bootstrap/cache \
	&& chown -R www-data:www-data /app

USER www-data

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]