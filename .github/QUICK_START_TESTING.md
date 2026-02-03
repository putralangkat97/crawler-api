# Quick Start Testing Guide

## TL;DR

### Start Everything

```bash
composer dev
```

### Run All Tests

```bash
composer test
```

### Manual API Testing

```bash
bash api-test.sh scrape_simple
bash api-test.sh crawl_start
bash api-test.sh all
```

---

## 3 Ways to Test

### 1️⃣ Automated Tests (Most Reliable)

```bash
# Run all tests
composer test

# Run specific test file
php artisan test tests/Feature/ScrapeAndCrawlTest.php

# Watch mode (auto-rerun on file changes)
php artisan test --watch

# Verbose output
php artisan test --verbose
```

**Files to write tests in:**

- `tests/Feature/ScrapeAndCrawlTest.php` - HTTP endpoint tests
- `tests/Unit/ServicesTest.php` - Service logic tests

---

### 2️⃣ Manual HTTP Testing (Fastest Feedback)

#### **Option A: Using the test script**

```bash
bash api-test.sh scrape_simple          # Single URL
bash api-test.sh scrape_batch           # Multiple URLs
bash api-test.sh scrape_chrome          # With rendering
bash api-test.sh crawl_start            # Start async job
bash api-test.sh crawl_status           # Check job status
bash api-test.sh all                    # Run all tests
```

#### **Option B: Direct cURL**

```bash
# Single URL
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com",
    "return_format": "markdown",
    "metadata": true
  }'

# Multiple URLs (batch)
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": [
      "https://en.wikipedia.org/wiki/Lionel_Messi",
      "https://www.bbc.com/sport/football",
      "https://www.espn.com/soccer"
    ],
    "return_format": "markdown",
    "metadata": true
  }'

# Async crawl
curl -X POST http://localhost:8000/api/v1/crawl \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com",
    "limit": 20,
    "depth": 2,
    "same_domain_only": true
  }'
```

---

### 3️⃣ Docker Integration Testing

```bash
# Start all services
docker-compose up -d

# Run tests in Docker
docker-compose exec app composer test

# View queue status
docker-compose logs -f horizon

# Stop everything
docker-compose down
```

---

## Setup Checklist

### Prerequisites

- [ ] PHP 8.2+
- [ ] Composer installed
- [ ] Docker & Docker Compose (for full testing)
- [ ] Node.js + Bun (for renderer)
- [ ] PostgreSQL 16+ or SQLite (for dev)
- [ ] Redis 7+ (for cache/queue)
- [ ] `jq` installed (`brew install jq` on macOS)

### Initial Setup

```bash
# 1. Install dependencies
composer install

# 2. Copy environment
cp .env.example .env

# 3. Generate key
php artisan key:generate

# 4. Setup database
php artisan migrate

# 5. Build renderer
cd renderer && bun install && bun run build && cd ..

# Or use setup script
./setup.sh
```

### Start Services

```bash
# Terminal 1: Laravel server (port 8000)
php artisan serve

# Terminal 2: Queue worker
php artisan queue:listen

# Terminal 3: Logs viewer
php artisan pail

# Or all at once
composer dev
```

---

## Payload Examples

### ✅ Scrape - Simple

```json
{
  "url": "https://example.com",
  "return_format": "markdown",
  "metadata": true
}
```

### ✅ Scrape - Batch (Your Provided Payload)

```json
{
  "url": [
    "https://en.wikipedia.org/wiki/Lionel_Messi",
    "https://www.bbc.com/sport/football/players/419613",
    "https://www.fourfourtwo.com/features/ranked-the-100-best-football-players-of-all-time/10",
    "https://www.espn.in/football/story/_/id/40490059/ranking-top-25-men-soccer-players-21st-century",
    "https://www.sportsmole.co.uk/football/barcelona/feature/messi-vs-ronaldo-who-is-the-goat-statistics-records-and-head-to-head-analysed_578359.html",
    "https://www.espn.com/soccer/story/_/id/37635141/pele-incredible-numbers-stats-1281-hundreds-goals-3-world-cups",
    "https://m.allfootballapp.com/news/All/Who-is-the-best-footballer-ever%C2%A0Pele-Maradona-Messi-or-Ronaldo/2992421",
    "https://luson.com/who-is-the-g-o-a-t-of-soccer/"
  ],
  "return_format": "markdown",
  "metadata": true,
  "timeout_ms": 15000
}
```

### ✅ Scrape - With Chrome Rendering

```json
{
  "url": "https://example.com",
  "request": "chrome",
  "wait_for": [
    {
      "type": "selector",
      "selector": ".main-content",
      "timeout_ms": 5000
    }
  ],
  "scroll": 3000,
  "return_format": "markdown"
}
```

### ✅ Crawl - Full Options

```json
{
  "url": "https://example.com",
  "limit": 50,
  "depth": 3,
  "request": "smart",
  "return_format": "markdown",
  "metadata": true,
  "same_domain_only": true,
  "allow_patterns": ["articles/.*", "blog/.*"],
  "deny_patterns": ["admin/.*", "login.*"],
  "include_pdf": true,
  "timeout_ms": 20000,
  "max_bytes": 15000000,
  "polite": {
    "concurrency": 3,
    "per_host_delay_ms": 1200,
    "jitter_ratio": 0.3,
    "max_errors": 25,
    "max_retries": 3
  }
}
```

---

## Response Examples

### ✅ Scrape Success

```json
{
  "success": true,
  "data": [
    {
      "url": "https://example.com",
      "final_url": "https://www.example.com/",
      "success": true,
      "status_code": 200,
      "content_type": "text/html",
      "request_used": "http",
      "content": "# Example Domain\n\nThis domain is...",
      "content_inline": true,
      "metadata": {
        "title": "Example Domain",
        "description": "Example Domain homepage",
        "links": [...],
        "images": [...]
      },
      "timing_ms": {
        "total": 1200,
        "fetch": 800,
        "render": null,
        "extract": 400
      }
    }
  ]
}
```

### ✅ Crawl Started

```json
{
  "success": true,
  "job_id": "crawl_550e8400-e29b-41d4-a716-446655440000"
}
```

### ❌ Error Response

```json
{
  "success": false,
  "error": {
    "code": "SSRF_BLOCKED",
    "message": "Private or restricted IP blocked"
  }
}
```

---

## Where to Test

| Location | Command | Purpose |
| ---------- | --------- | --------- |
| **Test files** | `php artisan test` | Automated, repeatable, CI/CD |
| **API script** | `bash api-test.sh` | Quick manual testing |
| **cURL direct** | `curl ...` | Direct API calls, debugging |
| **Postman/Insomnia** | Import `.env` vars | GUI testing, request history |
| **Docker** | `docker-compose up` | Full integration, production-like |

---

## Debugging Tips

### View Queue Status

```bash
php artisan queue:monitor          # Real-time stats
php artisan queue:listen --verbose # Detailed logs
php artisan queue:failed           # Failed jobs
php artisan queue:retry all        # Retry failed jobs
```

### Check Database

```bash
php artisan tinker
>>> CrawlJob::all()
>>> CrawlResult::where('job_id', 'crawl_...')->get()
>>> Cache::get('crawl:job_id:count')
```

### View Logs

```bash
php artisan pail                   # Real-time logs
tail -f storage/logs/laravel.log   # Last logs
docker-compose logs -f app         # Docker logs
```

### Clear State

```bash
php artisan cache:clear           # Clear cache
php artisan queue:clear           # Clear queue
php artisan queue:failed-delete    # Clear failed jobs
php artisan horizon:clear          # Clear Horizon stats
```

---

## Common Issues

### ❌ "Connection refused" on localhost:8000

→ Run `php artisan serve`

### ❌ Queue jobs not processing

→ Run `php artisan queue:listen` in another terminal

### ❌ "Renderer unavailable" error

→ Start renderer: `cd renderer && bun run dev`

### ❌ Database connection error

→ Check `.env` database credentials, run `php artisan migrate`

### ❌ Redis connection error

→ Start Redis: `redis-server` or `docker run -p 6379:6379 redis`

---

## Performance Benchmarks

Expected response times (local testing):

- Simple scrape (HTTP, small page): **0.8-2s**
- Batch scrape (3 URLs): **2-5s**
- Chrome rendering: **3-8s**
- Crawl job creation: **< 100ms** (async)
- Crawl results retrieval: **500ms-2s**

---

## Next Steps

1. ✅ Run `./setup.sh` to initialize
2. ✅ Run `composer dev` to start all services
3. ✅ Run `bash api-test.sh all` to test endpoints
4. ✅ Check `tests/Feature/ScrapeAndCrawlTest.php` for example tests
5. ✅ Read `.github/TESTING_GUIDE.md` for detailed documentation

---

**Questions?** Check:

- `.github/copilot-instructions.md` - Architecture guide
- `.github/TESTING_GUIDE.md` - Comprehensive testing docs
- `README.md` - Project overview
- `API_V1_DOCS.md` - API reference
