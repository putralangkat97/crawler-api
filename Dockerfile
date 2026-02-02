FROM dunglas/frankenphp:1-alpine

# Install Composer
RUN apk add --no-cache composer

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
