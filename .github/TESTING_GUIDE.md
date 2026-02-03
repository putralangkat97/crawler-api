# Testing Guide

## Overview

There are three ways to test the Scraper API:

1. **Pest Tests** - Automated unit & feature tests (`tests/` folder)
2. **cURL/Manual HTTP** - Direct API calls for quick validation
3. **Docker Compose** - Full integration testing with all services

---

## 1. Automated Tests with Pest

### Run All Tests

```bash
composer test
```

### Run Specific Test File

```bash
php artisan test tests/Feature/ScrapeTest.php
php artisan test tests/Unit/ImageExtractorTest.php
```

### Watch Mode (auto-rerun on file changes)

```bash
php artisan test --watch
```

### Test Structure

#### Feature Tests (`tests/Feature/`)

Test HTTP endpoints with real requests.

```php
<?php

test('POST /v1/scrape returns content with markdown format', function () {
    $response = $this->post('/api/v1/scrape', [
        'url' => 'https://example.com',
        'return_format' => 'markdown',
        'metadata' => true,
    ]);

    $response->assertStatus(200);
    expect($response->json('success'))->toBeTrue();
    expect($response->json('data.0.content'))->toBeString();
    expect($response->json('data.0.metadata.title'))->toBeString();
});

test('POST /v1/scrape rejects invalid URLs', function () {
    $response = $this->post('/api/v1/scrape', [
        'url' => 'not-a-valid-url',
    ]);

    $response->assertStatus(422);
    expect($response->json('success'))->toBeFalse();
});

test('POST /v1/crawl creates async job', function () {
    $response = $this->post('/api/v1/crawl', [
        'url' => 'https://example.com',
        'limit' => 10,
        'depth' => 2,
    ]);

    $response->assertStatus(200);
    expect($response->json('success'))->toBeTrue();
    expect($response->json('job_id'))->toMatch('/^crawl_/');
});
```

#### Unit Tests (`tests/Unit/`)

Test services in isolation with mocks.

```php
<?php

test('ImageExtractor scores larger images higher', function () {
    $extractor = new ImageExtractor();
    
    $images = [
        ['url' => 'large.jpg', 'width' => 800, 'height' => 600],
        ['url' => 'small.jpg', 'width' => 100, 'height' => 100],
    ];
    
    $scored = $extractor->scoreImages($images);
    expect($scored[0]['score'])->toBeGreaterThan($scored[1]['score']);
});

test('SsrfGuard blocks private IPs', function () {
    $guard = new SsrfGuard();
    
    $result = $guard->validate('http://127.0.0.1/admin');
    expect($result)->not->toBeNull();
    expect($result['code'])->toBe('SSRF_BLOCKED');
});

test('HttpFetcher respects max_bytes limit', function () {
    Http::fake([
        '*' => Http::response(str_repeat('x', 16_000_000)), // 16MB
    ]);
    
    $fetcher = new HttpFetcher(new SsrfGuard());
    $result = $fetcher->fetch('https://example.com', 8000, 15_000_000);
    
    expect($result['success'])->toBeFalse();
    expect($result['error']['code'])->toBe('MAX_BYTES_EXCEEDED');
});
```

---

## 2. Manual Testing with cURL

### Setup
Ensure the app is running:
```bash
# Terminal 1: Laravel server
php artisan serve

# Terminal 2: Queue worker
php artisan queue:listen

# Terminal 3: View logs (optional)
php artisan pail
```

Or use:
```bash
composer dev
```

### Single URL Scrape
```bash
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com",
    "return_format": "markdown",
    "metadata": true,
    "request": "smart"
  }'
```

### Multiple URLs (Batch)
```bash
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": [
      "https://en.wikipedia.org/wiki/Lionel_Messi",
      "https://www.example.com/article",
      "https://www.bbc.com/sport"
    ],
    "return_format": "markdown",
    "metadata": true
  }'
```

### With Browser Rendering (Chrome/JS)
```bash
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
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
  }'
```

### Async Crawl - Start Job
```bash
curl -X POST http://localhost:8000/api/v1/crawl \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com",
    "limit": 20,
    "depth": 2,
    "same_domain_only": true,
    "return_format": "markdown",
    "metadata": true
  }'
```

Response:
```json
{
  "success": true,
  "job_id": "crawl_550e8400-e29b-41d4-a716-446655440000"
}
```

### Check Crawl Job Status
```bash
curl http://localhost:8000/api/v1/crawl/crawl_550e8400-e29b-41d4-a716-446655440000 \
  -H "Content-Type: application/json"
```

Response:
```json
{
  "success": true,
  "data": {
    "job_id": "crawl_550e8400-e29b-41d4-a716-446655440000",
    "status": "running",
    "pages_count": 5,
    "started_at": "2026-02-03T10:00:00Z",
    "completed_at": null
  }
}
```

### Fetch Crawl Results (Paginated)
```bash
curl "http://localhost:8000/api/v1/crawl/crawl_550e8400-e29b-41d4-a716-446655440000/results?per_page=10" \
  -H "Content-Type: application/json"
```

### Payload Examples

#### Simple Scrape
```json
{
  "url": "https://example.com",
  "return_format": "markdown",
  "metadata": true
}
```

#### Multi-URL Scrape with Options
```json
{
  "url": [
    "https://en.wikipedia.org/wiki/Lionel_Messi",
    "https://www.bbc.com/sport/football",
    "https://www.espn.com/soccer"
  ],
  "request": "smart",
  "return_format": "markdown",
  "metadata": true,
  "timeout_ms": 15000,
  "max_bytes": 10000000
}
```

#### Crawl with All Options
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

## 3. Testing with Docker Compose

### Start All Services
```bash
docker-compose up -d
```

### Run Tests Inside Docker
```bash
docker-compose exec app composer test
```

### View Queue Status
```bash
docker-compose logs -f horizon
```

### View API Logs
```bash
docker-compose logs -f app
```

### Test via Docker Network
```bash
docker-compose exec app curl -X POST http://app:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com",
    "return_format": "markdown"
  }'
```

---

## 4. Debugging & Common Issues

### Check Database State
```bash
php artisan tinker
>>> CrawlJob::all()
>>> CrawlResult::where('job_id', 'crawl_...')->get()
```

### Monitor Queue
```bash
# View queue stats
php artisan queue:monitor

# Listen to queue with verbosity
php artisan queue:listen --verbose

# Check failed jobs
php artisan queue:failed
php artisan queue:retry all
```

### Test SSRF Protection
```bash
# Should be blocked (private IP)
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": "http://127.0.0.1:8000/admin"
  }'

# Should be blocked (invalid scheme)
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": "file:///etc/passwd"
  }'
```

### Clear Cache & State
```bash
php artisan cache:clear
php artisan queue:clear
php artisan horizon:clear
```

---

## 5. Response Examples

### Successful Scrape (Inline Content)
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
      "source_type": "website",
      "content": "# Example Domain\n\nThis is an example domain...",
      "content_inline": true,
      "content_url": null,
      "content_size": 1234,
      "metadata": {
        "title": "Example Domain",
        "description": "Example Domain homepage",
        "links": [
          {"url": "https://www.iana.org/", "text": "More information..."}
        ],
        "images": [
          {"url": "https://example.com/image.jpg", "score": 0.85}
        ]
      },
      "timing_ms": {
        "total": 1200,
        "fetch": 800,
        "render": null,
        "extract": 400
      },
      "error": null
    }
  ]
}
```

### Successful Crawl Start
```json
{
  "success": true,
  "job_id": "crawl_550e8400-e29b-41d4-a716-446655440000"
}
```

### Error Response
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

## 6. Performance Testing

### Siege (Load Testing)
```bash
brew install siege

# Create URLs file
cat > urls.txt << EOF
http://localhost:8000/api/v1/scrape
http://localhost:8000/api/v1/crawl
EOF

# Run load test (10 concurrent users, 100 requests each)
siege -f urls.txt -c 10 -r 10 -m
```

### Apache Bench
```bash
ab -n 100 -c 10 -p payload.json -T application/json http://localhost:8000/api/v1/scrape
```

---

## 7. Key Testing Checklist

- [ ] Single URL scrape works
- [ ] Batch URL scrape works
- [ ] Chrome rendering (`request=chrome`) works
- [ ] Markdown/raw/bytes formats work
- [ ] Metadata extraction works
- [ ] Crawl job creation works
- [ ] Crawl job polling works
- [ ] Crawl results retrieval works
- [ ] SSRF protection blocks private IPs
- [ ] SSRF protection blocks invalid schemes
- [ ] Rate limiting works (60 scrapes/min, 5 crawls/min)
- [ ] PDF extraction works
- [ ] Cache works (verify `cache_ttl`)
- [ ] Large content goes to R2 (≥131KB)
- [ ] Queue processing completes successfully
- [ ] Presigned URLs expire correctly
