# Test runner: docker build -t dra-paymenter-tests . && docker run --rm dra-paymenter-tests
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev \
    && docker-php-ext-install intl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-autoloader

COPY . .
RUN composer dump-autoload

CMD ["vendor/bin/phpunit"]
