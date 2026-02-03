# AI Coding Instructions for Scraper API

## Big Picture
Production-grade Laravel 12 API for web scraping (sync) and crawling (async) with ~100k users/day capacity. Two core request flows:
- **Sync Scrape** (`POST /v1/scrape`): Single-call to `ScrapeService` → returns full result with content (inline or R2 presigned URL)
- **Async Crawl** (`POST /v1/crawl`): Creates job → enqueues `CrawlStartJob` → BFS traversal via multi-queue pipeline (crawl:http|chrome, crawl:store, cleanup)

Core services in `app/Services/`: `ScrapeService` (HTTP/JS rendering routing), `ImageExtractor` (concurrent image pulling), `SpiderCrawler` (legacy page logic), `CrawlService` (job creation), plus infrastructure layers (`HttpFetcher`, `RendererClient`, `R2Storage`, `SmartRouter`, `UrlNormalizer`, `RobotsPolicy`).

## Architecture Decisions & Boundaries
- **Scrape vs Crawl**: Scrape is stateless per-URL; Crawl is stateful BFS using `CrawlJob` model + Cache-backed queue/visited. Crawl jobs have configurable `limit` (pages), `depth` (hops), `same_domain_only`, `allow_patterns`/`deny_patterns`.
- **Request routing**: `SmartRouter` auto-detects if JS is needed; `request=http|chrome|smart` param controls fallback. HTTP times out at `scrape_http_timeout_ms` (8s default), Chrome at `scrape_chrome_timeout_ms` (20s).
- **Storage**: Small content (≤131KB) inlined; large stored to R2 with presigned URLs (expires 600s). `bytes` format always uses R2. Crawl results *always* stored to R2.
- **External renderer**: Separate Fastify + Playwright service (`renderer:3001`) handles JS. Circuit breaker in `RendererClient` prevents cascades. Accepts `wait_for` (selector/timeout) + `scroll` (ms) options.
- **Politeness**: `PolitenessLimiter` enforces per-host 1200ms delay + 30% jitter, respects `robots.txt` via `RobotsPolicy`, exponential backoff on 429/503.
- **Rate limits**: Per-tenant sliding window via Redis (60 scrapes/min, 5 crawls/min). Rate middleware in routes.

## Key Services & Data Flow
1. **ScrapeService** (`app/Services/ScrapeService.php`):
   - Entry point for `POST /v1/scrape`. Tries HTTP first (if `request=smart`), falls back to Chrome on HTTP failure.
   - For each URL: `HttpFetcher::fetch()` → if needs render, `RendererClient::render()` → `Extractor::extractFromHtml()` → format (`markdown`/`text`/`raw`/`bytes`).
   - Returns array with `content` (inline), `content_url` (R2), `metadata` (links/images/title), `timing_ms` per request.
2. **ImageExtractor** (`app/Services/ImageExtractor.php`):
   - `extractFromUrls()` uses `Http::pool()` for concurrent fetches (default 5), pulls og/twitter meta images + all img tags.
   - Scoring: size (larger better), aspect ratio (1:1 preferred), filters GIFs (if `block_gifs=true`), checks prompt relevance (if `prompt_filter=true`).
   - Returns top N images (default 5) per URL with scores.
3. **CrawlService + Jobs** (`app/Services/CrawlService.php`, `app/Jobs/`):
   - `CrawlService::start()` → creates `CrawlJob` with `status=queued`, params_json → dispatches `CrawlStartJob` to `crawl:seed` queue.
   - `CrawlStartJob::handle()` → normalizes URL, sets Cache keys (count/host), dispatches `CrawlTaskJob` to `crawl:http|chrome`.
   - `CrawlTaskJob` → fetches page, extracts links (if depth < limit), enqueues children, updates count → on completion, `CrawlStoreJob` commits results to R2.
   - Uses Cache-backed queue (`crawl:{jobId}:queue`) + visited set (`crawl:{jobId}:visited`) to avoid cycles.
4. **HttpFetcher** (`app/Services/HttpFetcher.php`):
   - Wrapper around Laravel `Http`. Enforces SSRF guards (allowlist http/https, DNS rebinding cache 5min), private IP blocklist, max body size.
   - Returns `['success' => bool, 'body_path' => string, 'status_code' => int, 'content_type' => string, ...]` or error.
5. **RendererClient** (`app/Services/RendererClient.php`):
   - Posts to `renderer:3001/render` with URL + wait_for/scroll options. Circuit breaker tracks failures; opens after N consecutive errors.

## Request/Response Conventions
- **V1 endpoints** return `{ success: true, data: ... }` or `{ success: false, error: { code, message } }`. Legacy (`/scrape`, `/scrape/images`) return `{ success, data, count }`.
- **Controllers** are thin + invokable: validate input via `ScrapeRequest`/`CrawlRequest` → set defaults from config → call service → return JSON.
- **Request options** (shared across scrape/crawl): `request` (http|smart|chrome), `return_format` (markdown|commonmark|raw|text|bytes|empty), `metadata` (bool), `scroll` (ms), `wait_for` (array of {type, selector, timeout_ms}), `session` (bool, stores cookies in Cache), `timeout_ms`, `max_bytes`.
- **Crawl-specific options**: `limit` (max pages), `depth` (max hops), `same_domain_only` (bool), `allow_patterns`/`deny_patterns` (regex arrays), `include_pdf` (bool), `polite` (object with concurrency/delay/jitter/max_errors/max_retries).

## Caching Strategy
- **Page content**: `SpiderCrawler` caches scrape results by `md5(url + request + return_format)` (TTL: `cache_ttl` 1h default, PDFs 24h). Checks Cache first before fetching.
- **Crawl state**: Per-job Cache keys: `crawl:{jobId}:queue`, `crawl:{jobId}:visited`, `crawl:{jobId}:count`, `crawl:{jobId}:host`. TTL 24h.
- **Session cookies**: Stored at `session:{sessionId}:cookies` if `session=true`, TTL 1h.
- **Idempotency**: Crawl requests check `idempotency:{tenantId}:{payloadHash}:{idempotencyKey}`, returns existing `jobId` if hit (TTL 24h).

## Multi-Queue Architecture
- `crawl:seed`: Enqueue crawl start jobs (small, async initialization).
- `crawl:http` / `crawl:chrome`: Task jobs per request type (Horizon auto-scales via config/horizon.php).
- `crawl:store`: Store results to R2 + update CrawlResult.
- `default`: Cleanup job to purge temp R2 objects after 24h TTL.

## External Dependencies & Integration
- **Symfony DomCrawler + UriResolver**: Parse HTML, resolve relative URLs (use `UriResolver::resolve($baseUrl, $relativeUrl)`).
- **Browsershot** (Spatie): Wraps Puppeteer for JS rendering. Ensure Puppeteer/Chrome in Docker image.
- **PdfToText** (Spatie): Extract text from PDFs via `spatie/pdf-to-text`.
- **CommonMark + HTMLToMarkdown**: Convert HTML → Markdown (HtmlConverter for style preservation).
- **Readability**: Extract main content (article/text) from noisy HTML.
- **Fastify + Playwright** (renderer service): External microservice at `renderer_base_url` (env). Accepts POST /render with {url, wait_for, scroll, timeout_ms}.
- **AWS SDK**: R2 (Cloudflare S3) storage via `league/flysystem-aws-s3-v3`. Presigned URL generation at response time.

## Common Patterns & Gotchas
- **Always use `UriResolver`** for relative URLs: `UriResolver::resolve($baseUrl, $href)`.
- **Check `same_domain_only`** in crawl: compare `parse_url($url, PHP_URL_HOST)` against normalized seed domain.
- **Handle PDF content**: Check `content_type` for `application/pdf`, use `Pdf::getText()` (not HTML parsing).
- **R2 presigned URLs**: Include `content_expires_at` (now + 10min). Track `bytes_sha256` for binary responses.
- **Circuit breaker**: RendererClient fails gracefully; ScrapeService falls back to HTTP if renderer unavailable.
- **Crawl depth**: Tracked per job; stop if `depth >= max_depth` (default 25) or `visited.count() >= limit`.

## Renderer Integration & Circuit Breaker
The external Fastify + Playwright renderer service provides JS rendering via `RendererClient`:
- **Endpoint**: `POST http://renderer:3001/render` accepts `{url, wait_for[], scroll, timeout_ms}`
- **Response**: Returns `{final_url, status_code, content_type, html, timing_ms}`
- **Circuit breaker**: Tracks failures in Cache with key `renderer:failures` (TTL 60s). Opens after 5 consecutive failures, blocks requests for 90s via `renderer:circuit_open` flag.
- **Implementation** (`app/Services/RendererClient.php`):
  - `isBreakerOpen()`: Checks `Cache::get('renderer:circuit_open', false)`
  - `recordFailure()`: Increments `renderer:failures` counter; opens breaker if count ≥ 5
  - `recordSuccess()`: Clears failure counter on success
- **Fallback in ScrapeService**: If renderer is unavailable (circuit open or timeout), automatically falls back to HTTP for `request=smart`
- **Timeout conversion**: `timeout_ms` is divided by 1000 for Laravel `Http::timeout()` (in seconds)
- Error codes: `RENDERER_UNAVAILABLE` (circuit open), `RENDERER_ERROR` (failed request), `RENDERER_TIMEOUT` (network timeout)

## Multi-Queue Architecture & Horizon Isolation
Queue isolation prevents resource contention and enables per-queue auto-scaling:
- **crawl:http** (min 1, max 10 processes, timeout 30s): HTTP-only fetch jobs via `CrawlTaskJob`
- **crawl:chrome** (min 1, max 5 processes, timeout 25s): Chrome rendering jobs (higher resource cost)
- **crawl:store** (min 1, max 3 processes, timeout 10s): R2 storage writes via `CrawlStoreJob` (I/O-bound)
- **crawl:seed** (implicit, low-traffic): Enqueue start jobs from `CrawlService::start()`
- **cleanup** (min 1, max 3, timeout 5s): Purge temp R2 objects via `CleanupTmpObjectsJob` after 24h TTL
- **Configuration** (`config/horizon.php`): Each supervisor has `balance=auto`, `autoScalingStrategy=time`, `minProcesses=1`, `balanceMaxShift=1` for graceful scale-down
- **Queue routing**: `CrawlStartJob` dispatches to `crawl:seed`; `CrawlTaskJob` routes to `crawl:http` or `crawl:chrome` based on `params['request']`
- **Retries**: HTTP supervisor retries up to 3 times; Chrome retries 2 times (to prevent exhaustion)

## SSRF Protection Mechanisms
SSRF blocking is enforced via `SsrfGuard` (injected into `HttpFetcher`) with layered defense:
- **Scheme allowlist** (`app/Services/SsrfGuard.php`): Only `http` and `https` schemes permitted. Blocks `file://`, `gopher://`, `ftp://`, etc.
- **DNS resolution + caching**: Resolves hostname via `dns_get_record($host, DNS_A + DNS_AAAA)` (both IPv4 & IPv6), caches results for 5 minutes to prevent DNS rebinding attacks
- **Private IP blocklist**: Rejects any resolved IP in these ranges:
  - IPv4: `0.0.0.0/8`, `10.0.0.0/8`, `127.0.0.0/8`, `169.254.0.0/16` (link-local), `172.16.0.0/12`, `192.168.0.0/16`, `224.0.0.0/4` (multicast), `240.0.0.0/4` (reserved)
  - IPv6: Prefixes `fc`, `fd` (unique local), `fe80` (link-local), loopback `::`
- **Usage**: `HttpFetcher::fetch()` calls `$this->ssrfGuard->validate($url)` first; returns error if blocked
- **Error response**: `['code' => 'SSRF_BLOCKED', 'message' => '...']` returned to client
- **Max bytes check**: Streamed response limited via `maxBytes` parameter (default 15MB). Chunks read at 8KB intervals; aborts if exceeded
- **Test approach**: Mock `SsrfGuard` in tests or provide mock DNS responses via `dns_get_record` mocking

## Test Patterns with Pest
Pest uses closure-based testing with `expect()` syntax instead of `assert*()`. Key patterns for this codebase:
- **Feature test structure** (`tests/Feature/`):
  ```php
  test('POST /v1/scrape returns content inline', function () {
      $response = $this->post('/v1/scrape', [
          'url' => 'https://example.com',
          'return_format' => 'markdown',
      ]);
      $response->assertStatus(200);
      expect($response->json('success'))->toBeTrue();
      expect($response->json('data.0.content'))->toContain('...');
  });
  ```
- **Unit test structure** (`tests/Unit/`):
  ```php
  test('ImageExtractor scores larger images higher', function () {
      $extractor = new ImageExtractor();
      $images = $extractor->scoreImages([...]);
      expect($images[0]['score'])->toBeGreaterThan($images[1]['score']);
  });
  ```
- **Mocking services**: Use `$this->mock(ServiceClass::class, function ($mock) { ... })` for dependency mocking
- **Testing jobs**: Dispatch jobs with `Bus::fake()`, then assert job counts: `expect(Bus::dispatched(CrawlTaskJob::class))->toHaveCount(1)`
- **Database assertions**: Jobs and model tests may use `RefreshDatabase` (uncomment in `Pest.php` if needed)
- **HTTP mocking**: Use `Http::fake([pattern => response])` to mock external renderer/SSRF calls
- **Expectations API**: Custom expectation `toBeOne()` defined in `Pest.php`; extend with `expect()->extend('name', fn () => ...)`
- **Helper functions**: Define global test helpers in `Pest.php` (e.g., `function something() { ... }`) for reuse across tests

## Workflows & Commands
- **Local dev**: `composer dev` (runs server + queue + logs + Vite via concurrently). Or: `php artisan serve` + `php artisan queue:listen`.
- **Tests**: `composer test` (runs Pest; tests/ folder has Feature/ and Unit/).
- **Format**: `./vendor/bin/pint` (PSR-12).
- **Setup**: `./setup.sh` (composer install, migrations, renderer build).
- **Production**: `php artisan octane:start` (FrankenPHP, config/octane.php). Horizon supervises queues.
- **Database**: Migrations auto-create `crawl_jobs`, `crawl_results`, `users`, `tenants` tables.

## Style & Patterns
- **PSR-12** + Laravel conventions. Use `pa` shell alias for artisan commands.
- **Service-first**: Logic in services, controllers just validate + delegate.
- **Dependency injection**: Constructor-inject services; let container resolve.
- **Enum + Model**: Use `CrawlJob` model, cast `params_json` to array.
- **Exceptions**: Throw specific exceptions (`HttpFetchException`, `RenderException`), catch + return error JSON in controllers.
- **Test structure**: Unit tests for services/utils; Feature tests for endpoints (use Factory for models, Pest's `expect()` syntax).
