# Production image: PHP 8.4 + FrankenPHP running Laravel Octane (app stays in memory, ~5-10x
# faster than FPM). The same image runs web, Horizon workers and the scheduler.
#
# Security floor (2026-10-25): PHP 8.4.27 fixes CVE-2026-109618 / CVE-2026-109619 (pre-auth RCE in
# request handling, affects FrankenPHP); serversideup/php v5.0.2 ships it. The tag below floats,
# so always rebuild with `docker compose build --pull` / `docker build --pull` and the guard
# underneath fails the build if a cached, unpatched base image is used.
FROM serversideup/php:8.4-frankenphp

USER root
RUN php -r 'exit(version_compare(PHP_VERSION, "8.4.27", ">=") ? 0 : 1);' \
 || (echo "PHP $(php -r 'echo PHP_VERSION;') < 8.4.27: rebuild with --pull" >&2 && exit 1)
# Refresh the CA trust store (eMudhra intermediate CA compromise, Oct 2026): outbound Http/Guzzle
# calls trust the container bundle, not the browser's.
RUN apt-get update && apt-get install -y --only-upgrade ca-certificates && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions intl pdo_pgsql pdo_mysql redis zip bcmath pcntl
USER www-data

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
RUN composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction \
 && php artisan storage:link || true

ENV OCTANE_SERVER=frankenphp \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    PHP_OPCACHE_ENABLE=1 \
    SSL_MODE=off

EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=3s CMD curl -fsS http://localhost:8080/health || exit 1
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8080", "--workers=auto", "--max-requests=1000"]
