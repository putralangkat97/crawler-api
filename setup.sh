#!/bin/bash
set -e

echo "🚀 Starting Scraper API Quick Setup..."

# Check if .env exists
if [ ! -f .env ]; then
    echo "📝 Creating .env from .env.example..."
    cp .env.example .env
    echo "⚠️  Please edit .env with your R2 credentials and API keys"
    exit 1
fi

# Check if APP_KEY is set
if ! grep -q "APP_KEY=base64:" .env; then
    echo "🔑 Generating application key..."
    php artisan key:generate
fi

# Start docker services
echo "🐳 Starting Docker services (postgres, redis, renderer)..."
docker-compose up -d postgres redis

# Wait for postgres
echo "⏳ Waiting for PostgreSQL to be ready..."
until docker-compose exec -T postgres pg_isready -U app > /dev/null 2>&1; do
    sleep 1
done

# Run migrations (use localhost since we're running from host)
echo "📦 Running database migrations..."
DB_HOST=localhost REDIS_HOST=localhost php artisan migrate --force

# Check if renderer is built
if [ ! -d "renderer/node_modules" ]; then
    echo "📦 Installing renderer dependencies..."
    cd renderer && bun install && cd ..
fi

# Start renderer in background
echo "🎭 Starting renderer service..."
docker-compose up -d renderer

echo ""
echo "✅ Setup complete!"
echo ""
echo "📚 Next steps:"
echo ""
echo "1. Edit .env with your R2 credentials:"
echo "   R2_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com"
echo "   R2_ACCESS_KEY_ID=<your-key>"
echo "   R2_SECRET_ACCESS_KEY=<your-secret>"
echo "   R2_BUCKET=<your-bucket>"
echo ""
echo "2. Start all services:"
echo "   docker-compose up -d"
echo ""
echo "3. Access API:"
echo "   Via Caddy: http://localhost:8080"
echo "   Direct to API (debugging): http://localhost:8000"
echo ""
echo "4. Test the API:"
echo "   curl -X POST http://localhost:8080/api/v1/scrape \\"
echo "     -H 'X-API-Key: your-api-key-here' \\"
echo "     -H 'Content-Type: application/json' \\"
echo "     -d '{\"url\": \"https://example.com\"}'"
echo ""
echo "5. Horizon dashboard: http://localhost:8080/horizon"
echo ""
