FROM dunglas/frankenphp:1-php8.4-alpine

# Install Composer + PHP extensions for FrankenPHP
RUN apk add --no-cache git composer

RUN install-php-extensions \
    session \
    fileinfo \
    tokenizer \
    dom \
    xml \
    opcache \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    curl \
    mbstring \
    intl \
    zip \
    bcmath

WORKDIR /app

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-scripts

RUN chown -R www-data:www-data storage bootstrap/cache database

RUN php artisan config:cache && php artisan route:cache

EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--host=0.0.0.0", "--port=8000"]
