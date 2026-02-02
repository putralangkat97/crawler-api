FROM dunglas/frankenphp:1-alpine

WORKDIR /app

# Copy source dulu
COPY . .

# Install Composer deps
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Permission
RUN chown -R www-data:www-data storage bootstrap/cache database

# Production optimize (skip migration untuk API sederhana)
RUN php artisan config:cache \
    && php artisan route:cache

EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
