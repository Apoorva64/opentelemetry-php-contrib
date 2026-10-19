#!/bin/sh
set -e

if [ "$OTEL_EXT" = "off" ]; then
    rm -f /usr/local/etc/php/conf.d/docker-php-ext-opentelemetry.ini
fi

# Production-like deploy: build the Symfony cache with the variant's code in place
rm -rf var/cache/prod
php bin/console cache:warmup -q
chown -R www-data:www-data var

exec php-fpm
