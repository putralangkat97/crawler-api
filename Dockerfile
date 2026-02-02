FROM dunglas/frankenphp:1-php8.4-alpine

RUN apk add --no-cache git curl unzip

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
    bcmath \
    pcntl

RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

COPY . .

RUN composer install

RUN chown -R www-data:www-data storage bootstrap/cache database

RUN php artisan config:clear && php artisan config:cache && php artisan route:cache

EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
