FROM dunglas/frankenphp:1-alpine

# Install system deps + PHP extensions
RUN apk add --no-cache \
    composer \
    php84-session \
    php84-fileinfo \
    php84-tokenizer \
    php84-dom \
    php84-xml \
    php84-opcache \
    php84-pdo \
    php84-pdo_pgsql \
    php84-pdo_mysql \
    php84-pdo_sqlite \
    php84-curl \
    php84-mbstring \

WORKDIR /app

COPY . .

# Install deps
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Permission
RUN chown -R www-data:www-data storage bootstrap/cache database

# Optimize
RUN php artisan config:cache && php artisan route:cache

EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
