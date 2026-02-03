# Implementation Summary

## What Was Built

A production-grade Laravel 12 + Octane + FrankenPHP scraping API optimized for 100k users/day with:

### Core Services Created

1. **ScrapeService** - Synchronous scraping with R2 storage integration
2. **CrawlService** - Async job orchestration
3. **HttpFetcher** - Streaming HTTP with max-bytes cutoff and SSRF protection
4. **RendererClient** - External renderer integration with circuit breaker
5. **Extractor** - Content extraction (Readability + HTML-to-Markdown)
6. **R2Storage** - S3-compatible storage with presigned URLs
7. **SsrfGuard** - Allowlist + DNS validation + private IP blocking
8. **RobotsPolicy** - Cached robots.txt compliance
9. **PolitenessLimiter** - Per-host delay + jitter + backpressure
10. **UrlNormalizer** - URL resolution and normalization
11. **SmartRouter** - HTTP vs Chrome decision logic

### Queue Jobs Created

1. **CrawlStartJob** - Seeds the crawl queue
2. **CrawlTaskJob** - Fetches, extracts, stores, and enqueues links
3. **CrawlStoreJob** - Placeholder for future batching
4. **CleanupTmpObjectsJob** - Placeholder for R2 cleanup

### Models & Migrations

1. **Tenant** - API key storage (hashed)
2. **CrawlJob** - Job tracking with status
3. **CrawlResult** - Crawl outputs with R2 keys
4. **Migrations** - tenants, crawl_jobs, crawl_results, failed_jobs tables

### Middleware & Security

1. **ApiKeyAuth** - X-API-Key validation with tenant binding
2. **TenantRateLimit** - Per-tenant sliding window rate limiting

### Controllers

1. **ScrapeController** - Synchronous scraping endpoint
2. **CrawlController** - Async crawl CRUD (store, show, results, destroy)
3. **HealthController** - Health and readiness checks

### Infrastructure

1. **Horizon Config** - Multi-queue autoscaling (crawl:http, crawl:chrome, crawl:store, cleanup)
2. **Octane Config** - FrankenPHP with flush configuration
3. **Docker Compose** - postgres, redis, laravel-api, horizon, renderer, caddy
4. **Renderer Service** - Bun + Playwright with concurrency semaphore

## Architecture Decisions

### 1. Full Presign-in-Response (No Lazy Generation)

- **Decision**: Generate all presigned URLs at API response time
- **Rationale**: Avoids N+1 calls; S3 presign API is cheap (~1ms); prevents TTL waste
- **Trade-off**: Small latency increase per response (acceptable at 20 results/page)

### 2. SSRF Protection via Allowlist + DNS Validation

- **Decision**: Allowlist http/https only + resolve A/AAAA + validate IPs + 5min DNS cache
- **Rationale**: More secure than blocklist; prevents DNS rebinding; protects against metadata service attacks
- **Trade-off**: 5min TOCTOU window (acceptable for performance)

### 3. Multi-Queue Isolation with Horizon

- **Decision**: Separate queues (seed/http/chrome/store/cleanup) with dedicated supervisors
- **Rationale**: Prevents chrome bottlenecks from blocking http scraping; allows independent scaling
- **Trade-off**: More complex queue management (worth it for stability)

### 4. External Renderer with Circuit Breaker

- **Decision**: Bun Playwright as microservice; circuit breaker prevents cascading failures
- **Rationale**: Isolates browser crashes; prevents API workers from hanging; scales independently
- **Trade-off**: Network latency between services (acceptable vs in-process Browsershot)

### 5. R2 Storage with Inline Threshold

- **Decision**: Inline text if ≤ 131KB (INLINE_TEXT_MAX_BYTES), else R2 with presigned URL
- **Rationale**: Reduces DB size; avoids API payload bloat; allows tuning threshold without code changes
- **Trade-off**: Clients must handle presigned URLs (acceptable for production API)

### 6. Async-Only Crawling

- **Decision**: POST /v1/crawl always enqueues; never blocks
- **Rationale**: Prevents timeouts; allows long-running crawls (1000+ pages, 30min+)
- **Trade-off**: Polling required for results (acceptable; industry standard)

## Performance Characteristics

### Expected Throughput (100k users/day)

- **Users/day**: 100,000
- **Requests/day**: ~150,000 (1.5 scrapes per user avg)
- **Peak RPS**: ~10 req/s (assuming 4h peak window)
- **Worker capacity**:
  - FrankenPHP: 10 workers × 100 req/s each = 1000 req/s (sufficient)
  - Horizon: 10 http + 5 chrome + 3 store = 18 workers (sufficient for queue processing)

### Resource Requirements

- **API Server**: 4 CPU, 8GB RAM (FrankenPHP workers)
- **Horizon**: 2 CPU, 4GB RAM (queue workers)
- **Renderer**: 2 CPU, 4GB RAM (2 concurrent browsers)
- **Redis**: 1 CPU, 2GB RAM (rate limits, cache, queue)
- **PostgreSQL**: 2 CPU, 4GB RAM (read replicas recommended for large crawls)

### Latency Targets

- **Scrape (http)**: p95 < 2s
- **Scrape (chrome)**: p95 < 5s
- **Crawl enqueue**: p95 < 200ms
- **Results fetch**: p95 < 500ms (20 results with presign)

## Configuration Defaults

```env
# Tuned for 100k/day
RATE_LIMIT_SCRAPE_PER_MIN=60
RATE_LIMIT_CRAWL_PER_MIN=5
MAX_SCRAPE_URLS_PER_REQUEST=10
MAX_CRAWL_PAGES_HARD_CAP=1000
MAX_CRAWL_RUNTIME_SECONDS=1800

# Storage thresholds
INLINE_TEXT_MAX_BYTES=131072    # 128KB (increase to 262144/524288/1048576 as needed)
MAX_BYTES_DEFAULT=15000000      # 15MB streaming cutoff
PRESIGN_TTL_SECONDS=600         # 10min presigned URL TTL

# Timeouts
SCRAPE_HTTP_TIMEOUT_MS=8000     # 8s for fast http
SCRAPE_CHROME_TIMEOUT_MS=20000  # 20s for JS rendering

# Politeness defaults
per_host_delay_ms=1200          # 1.2s + jitter
jitter_ratio=0.3                # ±30%
concurrency=3                   # overall crawl concurrency
```

## Testing Checklist

- [ ] Scrape single URL (http, chrome, smart)
- [ ] Scrape array of URLs (up to 10)
- [ ] Scrape with large response (> 131KB → content_url)
- [ ] Scrape PDF with return_format=bytes
- [ ] Crawl with depth/limit
- [ ] Crawl results pagination (cursor)
- [ ] Cancel crawl mid-flight
- [ ] Rate limit enforcement (429 after threshold)
- [ ] SSRF protection (block 127.0.0.1, 169.254.169.254, private IPs)
- [ ] Renderer circuit breaker (5 failures → open)
- [ ] Idempotency-Key header (repeated POST /v1/crawl)
- [ ] Health endpoints (/health, /ready)
- [ ] Horizon dashboard (queue depth, failed jobs)

## Deployment Steps

1. **Set environment variables** (R2 credentials, API keys, database, redis)
2. **Run migrations**: `php artisan migrate`
3. **Publish Horizon assets**: `php artisan horizon:publish`
4. **Start services**:

   ```bash
   docker-compose up -d postgres redis renderer
   php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --workers=auto --max-requests=500
   php artisan horizon
   ```

5. **Create tenant**: Insert into `tenants` table with hashed API key
6. **Test endpoints** using curl examples in README
7. **Monitor Horizon**: [http://localhost/horizon](http://localhost/horizon)

## Known Limitations & Future Work

1. **Renderer concurrency**: Default 2; increase to 4–8 on multi-core for higher throughput
2. **Database scaling**: Partition `crawl_results` by job_id at 10M+ rows
3. **R2 cleanup**: Use R2 lifecycle rules for automatic tmp/ object deletion
4. **Metrics**: Add Prometheus exporter for queue depth, renderer latency, 429 rates
5. **Distributed rate limiting**: Current Redis-based approach is per-node; use Redis sorted sets for multi-node coordination
6. **Crawl runtime monitoring**: Add scheduled job to mark timed-out crawls as failed
7. **Retry logic**: Implement exponential backoff for transient errors (429, 503, network timeouts)
8. **Result streaming**: For very large crawls (10k+ pages), consider streaming results via webhooks instead of pagination

## Maintenance

- **Weekly**: Review Horizon failed jobs; investigate recurring failures
- **Monthly**: Analyze rate limit logs; adjust thresholds per tenant tier
- **Quarterly**: Review R2 storage growth; optimize inline threshold if needed
- **As needed**: Scale Horizon workers based on queue depth; add renderer capacity if backpressure persists

## Assumptions

1. **R2 availability**: Cloudflare R2 is available and accessible with provided credentials
2. **Renderer reliability**: Bun + Playwright is stable; circuit breaker handles failures
3. **DNS reliability**: DNS resolution is fast (<50ms p95); 5min cache is acceptable
4. **Network security**: API is behind reverse proxy (Caddy) with rate limiting and TLS termination
5. **Crawl targets**: Most sites allow scraping; robots.txt is honored by default
6. **Client behavior**: Clients fetch presigned URLs immediately (within 10min TTL)
7. **Database performance**: PostgreSQL can handle 100k rows/day inserts (acceptable for crawl_results)
8. **Queue processing**: Redis queue is reliable; Horizon autoscaling prevents backlog

## Success Metrics

- **API availability**: 99.9% uptime
- **Scrape success rate**: >95% (excluding client errors)
- **Crawl completion rate**: >90% (within max runtime)
- **P95 latency**: <2s (http scrape), <5s (chrome scrape)
- **Queue depth**: <1000 jobs (http), <500 (chrome)
- **Failed jobs**: <1% of total jobs
- **Rate limit 429s**: <5% of requests
- **Renderer circuit open**: <1% of time
