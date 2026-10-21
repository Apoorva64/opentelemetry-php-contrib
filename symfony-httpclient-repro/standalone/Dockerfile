FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libcurl4-openssl-dev \
 && docker-php-ext-install curl \
 && pecl install opentelemetry && docker-php-ext-enable opentelemetry
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
