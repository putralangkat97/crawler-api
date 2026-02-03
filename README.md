# Production-Grade Scrape + Crawl API

A Laravel 12 + Octane + FrankenPHP scraping API optimized for **100k users/day**, featuring secure SSRF protection, async crawling, multi-queue isolation, R2 storage with presigned URLs, and external renderer integration.

## Architecture Overview

- **Synchronous Scraping** (`POST /v1/scrape`): Returns results immediately with full presigned URLs for large content/bytes.
- **Asynchronous Crawling** (`POST /v1/crawl`): Enqueues BFS-based crawl jobs with multi-queue isolation (seed/http/chrome/store/cleanup).
- **External Renderer**: Bun + Playwright microservice for JS-heavy pages; circuit breaker prevents cascading failures.
- **R2 Storage**: Cloudflare R2 (S3-compatible) for large text content and binary outputs; presigned URLs generated at response time.
- **SSRF Protection**: Allowlist (http/https only) + DNS resolution validation + private IP blocklist + DNS rebinding prevention (5min cache).
- **Politeness**: Per-host delay (1200ms) + jitter (±30%) + robots.txt compliance + exponential backoff on 429/503.
- **Rate Limiting**: Per-tenant sliding window (60 scrapes/min, 5 crawls/min) via Redis.
- **Queue Isolation**: Horizon autoscaling with separate supervisors for crawl:http, crawl:chrome, crawl:store, cleanup.
- **Octane Stability**: FrankenPHP with `--max-requests=500` worker recycling; flushed singletons prevent state leaks.

## Features

- **Request types**: `http` (fast), `chrome` (JS rendering), `smart` (auto-detect & fallback)
- **Return formats**: `markdown`, `commonmark`, `raw`, `text`, `xml`, `bytes`, `empty`
- **Storage policy**:
  - Scrape: inline if ≤ 131KB, else presigned `content_url`; `bytes` always use `bytes_url`
  - Crawl: always store to R2 (`content_r2_key`), presigned URLs in results API
- **Metadata extraction**: title, description, links, images via Readability + Symfony DomCrawler
- **PDF support**: extract text via `spatie/pdf-to-text`
- **Idempotency**: `Idempotency-Key` header for crawl requests (24h TTL)
- **Health endpoints**: `GET /health` (DB+Redis), `GET /ready` (queue+renderer)

## Setup

### Requirements

- PHP 8.2+
- Composer
- Docker + Docker Compose
- Bun 1.0+ (for renderer)
- PostgreSQL 16+
- Redis 7+

### Local Development (Docker-only)

```bash
# 1. Quick setup (migrations + dependencies)
./setup.sh

# 2. Configure R2 credentials in .env
# R2_ENDPOINT, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET

# 3. Start all services
docker-compose up -d

# 4. Access API
# Via Caddy (recommended): http://localhost:8080
# Direct to API (debugging): http://localhost:8000
# Horizon dashboard: http://localhost:8080/horizon
```

**Note**: For local development, all services run in Docker. No need for separate terminals or host-based PHP commands.

### Environment Variables (Defaults)

```env
# Database (Docker hostnames)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_DATABASE=app
DB_USERNAME=app
DB_PASSWORD=app

# Redis (Docker hostname)
REDIS_HOST=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis

# R2 Storage
R2_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com
R2_ACCESS_KEY_ID=<your-key>
R2_SECRET_ACCESS_KEY=<your-secret>
R2_BUCKET=<your-bucket>
R2_REGION=auto
PRESIGN_TTL_SECONDS=600          # Presigned URL TTL
TMP_OBJECT_TTL_HOURS=24          # Cleanup threshold
INLINE_TEXT_MAX_BYTES=131072     # 128KB (increase to 262144/524288/1048576 as needed)

# Rate Limits
RATE_LIMIT_SCRAPE_PER_MIN=60
RATE_LIMIT_CRAWL_PER_MIN=5

# Timeouts & Limits
SCRAPE_HTTP_TIMEOUT_MS=8000
SCRAPE_CHROME_TIMEOUT_MS=20000
MAX_BYTES_DEFAULT=15000000       # 15MB
MAX_SCRAPE_URLS_PER_REQUEST=10
MAX_CRAWL_PAGES_HARD_CAP=1000
MAX_CRAWL_RUNTIME_SECONDS=1800

# Renderer
RENDERER_BASE_URL=http://renderer:3001
RENDERER_MAX_CONCURRENCY=2
RENDERER_TIMEOUT_MS=20000

# API Keys (comma-separated)
API_KEYS=your-api-key-here
```

## API Examples

### 1. Scrape (Small Markdown - Inline)

```bash
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "X-API-Key: your-api-key-here" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com",
    "request": "smart",
    "return_format": "markdown",
    "metadata": true
  }'
```

**Response:**
```json
{
  "success": true,
  "data": [{
    "url": "https://example.com",
    "final_url": "https://example.com/",
    "success": true,
    "status_code": 200,
    "content_type": "text/html",
    "request_used": "http",
    "source_type": "html",
    "content": "# Example Domain\n\nThis domain is for use in...",
    "content_inline": true,
    "content_url": null,
    "content_expires_at": null,
    "content_size": 1256,
    "bytes_url": null,
    "bytes_expires_at": null,
    "bytes_size": null,
    "bytes_sha256": null,
    "metadata": {
      "title": "Example Domain",
      "description": "Example Domain"
    },
    "links": ["https://www.iana.org/domains/example"],
    "images": [],
    "timing_ms": {"total": 245, "fetch": 220, "render": null, "extract": 25},
    "error": null
  }]
}
```

### 2. Scrape (Large Markdown - Presigned URL)

```bash
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "X-API-Key: your-api-key-here" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://en.wikipedia.org/wiki/Web_scraping",
    "request": "http",
    "return_format": "markdown"
  }'
```

**Response (content > 131KB):**
```json
{
  "success": true,
  "data": [{
    "url": "https://en.wikipedia.org/wiki/Web_scraping",
    "success": true,
    "content": "",
    "content_inline": false,
    "content_url": "https://<accountid>.r2.cloudflarestorage.com/tmp/env/scrape/content/<sha256>.txt?X-Amz-Algorithm=...",
    "content_expires_at": "2026-02-03T10:15:00.000Z",
    "content_size": 256000,
    "timing_ms": {"total": 1820, "fetch": 1650, "extract": 170}
  }]
}
```

### 3. Scrape PDF (Bytes - Presigned URL)

```bash
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "X-API-Key: your-api-key-here" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf",
    "request": "http",
    "return_format": "bytes"
  }'
```

**Response:**
```json
{
  "success": true,
  "data": [{
    "url": "https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf",
    "success": true,
    "source_type": "pdf",
    "bytes_url": "https://<accountid>.r2.cloudflarestorage.com/tmp/env/scrape/bytes/<sha256>.pdf?X-Amz-Algorithm=...",
    "bytes_expires_at": "2026-02-03T10:20:00.000Z",
    "bytes_size": 13264,
    "bytes_sha256": "b5bb9d8014a0f9b1d61e21e796d78dccdf1352f23cd32812f4850b878ae4944c"
  }]
}
```

### 4. Async Crawl (Start + Status + Results)

**Start crawl:**
```bash
curl -X POST http://localhost:8000/api/v1/crawl \
  -H "X-API-Key: your-api-key-here" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com",
    "depth": 2,
    "limit": 10,
    "request": "smart",
    "return_format": "markdown",
    "same_domain_only": true,
    "include_pdf": true,
    "polite": {
      "per_host_delay_ms": 1200,
      "jitter_ratio": 0.3,
      "concurrency": 3
    }
  }'
```

**Response:**
```json
{
  "success": true,
  "job_id": "crawl_9c5e8a12-3456-7890-abcd-ef1234567890"
}
```

**Check status:**
```bash
curl http://localhost:8000/api/v1/crawl/crawl_9c5e8a12-3456-7890-abcd-ef1234567890 \
  -H "X-API-Key: your-api-key-here"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "job_id": "crawl_9c5e8a12-3456-7890-abcd-ef1234567890",
    "status": "running",
    "created_at": "2026-02-03T10:00:00.000Z",
    "updated_at": "2026-02-03T10:01:30.000Z",
    "canceled_at": null,
    "error": null
  }
}
```

**Get results (cursor-based pagination):**
```bash
curl "http://localhost:8000/api/v1/crawl/crawl_9c5e8a12-3456-7890-abcd-ef1234567890/results?limit=5" \
  -H "X-API-Key: your-api-key-here"
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "url": "https://example.com",
      "final_url": "https://example.com/",
      "success": true,
      "status_code": 200,
      "content_type": "text/html",
      "request_used": "http",
      "source_type": "html",
      "content": "",
      "content_inline": false,
      "content_url": "https://<accountid>.r2.cloudflarestorage.com/tmp/env/crawl_9c5e8a12.../content/<sha256>.txt?...",
      "content_expires_at": "2026-02-03T10:15:00.000Z",
      "content_size": 5420,
      "bytes_url": null,
      "bytes_size": null,
      "bytes_sha256": null,
      "metadata": {"title": "Example Domain", "description": "..."},
      "links": ["https://www.iana.org/domains/example"],
      "images": [],
      "timing_ms": {"total": 312, "fetch": 280, "extract": 32},
      "error": null
    }
  ],
  "next_cursor": "eyJjcmVhdGVkX2F0IjoiMjAyNi0wMi0wM1QxMDowMTowMC4wMDBaIiwiaWQiOjV9"
}
```

**Cancel crawl:**
```bash
curl -X DELETE http://localhost:8000/api/v1/crawl/crawl_9c5e8a12-3456-7890-abcd-ef1234567890 \
  -H "X-API-Key: your-api-key-here"
```

## Performance & Scaling (100k/day)

- **Octane + FrankenPHP**: `--workers=auto` uses CPU cores; `--max-requests=500` recycles workers every 500 requests to prevent memory leaks.
- **Horizon Autoscaling**: Scales from 1–10 workers per queue based on load; tune `maxProcesses` per CPU/memory capacity.
- **Renderer Concurrency**: `RENDERER_MAX_CONCURRENCY=2` (safe default); increase to 4–8 on multi-core with 4GB+ RAM.
- **Rate Limits**: Adjust `RATE_LIMIT_SCRAPE_PER_MIN` and `RATE_LIMIT_CRAWL_PER_MIN` per tenant tier.
- **R2 Presigned URLs**: 600s TTL (short-lived bearer tokens); clients should fetch content immediately after receiving URLs.
- **Database**: Add read replicas for crawl results queries at scale; partition `crawl_results` by `job_id` for large crawls.
- **Caching**: Redis for DNS results (5min), robots.txt (6–24h), politeness tracking, rate limits.

## Testing

```bash
# Run all tests
php artisan test

# Run specific feature test
php artisan test --filter=ScrapeControllerTest
```

## Monitoring

- **Horizon Dashboard**: http://localhost:8000/horizon (queue depth, throughput, failed jobs)
- **Health Endpoints**:
  - `GET /health` - DB + Redis connectivity
  - `GET /ready` - Queue workers + renderer reachability
- **Logs**: Structured JSON logs to stdout (request_id, tenant_id, job_id, url, timings)

## Security

- **SSRF Protection**: Blocks private IPs (10.0.0.0/8, 127.0.0.0/8, 169.254.0.0/16, 192.168.0.0/16, etc.) and validates DNS resolution.
- **Presigned URLs**: Short-lived (600s) bearer tokens; treat as secrets; do not log or expose in public responses.
- **API Keys**: Store hashed in `tenants` table; fallback to `API_KEYS` env for bootstrap.
- **Rate Limiting**: Per-tenant sliding window prevents abuse.
- **Max Bytes**: Streaming cutoff prevents memory exhaustion from large responses.

## License

MIT
