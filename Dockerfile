FROM dunglas/frankenphp:1-alpine

# Install dependencies
RUN apk add --no-cache git $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_sqlite sqlite3 \
    && apk del $PHPIZE_DEPS

WORKDIR /app

# Copy source
COPY . .

# Install Composer deps
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Permission
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

# Production optimize
RUN php artisan config:cache \
    && php artisan route:cache

# Expose port
EXPOSE 8000

# FrankenPHP command
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
