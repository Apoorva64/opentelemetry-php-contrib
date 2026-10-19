FROM php:8.3-fpm AS fpm
RUN pecl install opentelemetry >/dev/null && docker-php-ext-enable opentelemetry opcache
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-bench.ini
COPY docker/pool.conf /usr/local/etc/php-fpm.d/zz-bench.conf
COPY docker/entrypoint.sh /usr/local/bin/bench-entrypoint
ARG APP
COPY work/apps/${APP} /app
WORKDIR /app
ENTRYPOINT ["bench-entrypoint"]

FROM fpm AS prof
RUN pecl install excimer >/dev/null && docker-php-ext-enable excimer
COPY docker/prof.php /usr/local/lib/prof.php
RUN echo "auto_prepend_file=/usr/local/lib/prof.php" > /usr/local/etc/php/conf.d/zz-prof.ini
