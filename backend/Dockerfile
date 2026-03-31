FROM dunglas/frankenphp:php8.2-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    curl \
    git \
    icu-dev \
    libzip-dev \
    sqlite \
    sqlite-dev \
    mysql-client \
    oniguruma-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install \
    bcmath \
    intl \
    pdo \
    pdo_sqlite \
    pdo_mysql \
    pcntl \
    zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-interaction --no-progress --prefer-dist

RUN cp .env.example .env || true \
    && php artisan key:generate --force

RUN chmod +x entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["./entrypoint.sh"]
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=4"]
