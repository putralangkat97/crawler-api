# Spider.cloud-like API Documentation

## Overview

This API provides two main endpoints for web scraping and crawling with Redis caching, Chrome rendering, and advanced content extraction.

## Endpoints

### 1. POST `/api/v1/scrape` - Single Page Scraping

Scrape a single URL with advanced options.

#### Request Body

```json
{
  "url": "https://example.com",
  "request": "smart",
  "return_format": "markdown",
  "metadata": true,
  "scroll": 5000,
  "wait_for": ".content",
  "session": false,
  "cache": true,
  "cache_ttl": 3600
}
```

#### Parameters

- **url** (required, string): The URL to scrape
- **request** (optional, string): Request type - `http`, `chrome`, or `smart` (default: `smart`)
  - `http`: Fast HTTP-only request
  - `chrome`: Always use headless Chrome (slower but handles JS)
  - `smart`: HTTP first, fallback to Chrome if needed
- **return_format** (optional, string): Output format - `markdown`, `commonmark`, `raw`, `text`, `bytes`, `empty` (default: `markdown`)
- **metadata** (optional, boolean): Include metadata in response (default: `true`)
- **scroll** (optional, integer): Scroll duration in milliseconds for infinite scroll (0-30000, requires `request: chrome`)
- **wait_for** (optional, string): CSS selector to wait for before scraping (requires `request: chrome`)
- **session** (optional, boolean): Persist cookies across requests (default: `false`)
- **cache** (optional, boolean): Enable Redis caching (default: `true`)
- **cache_ttl** (optional, integer): Cache TTL in seconds (0-86400, default: 3600)

#### Response

```json
{
  "success": true,
  "data": {
    "success": true,
    "url": "https://example.com",
    "final_url": "https://example.com",
    "status_code": 200,
    "content_type": "text/html",
    "request_used": "http",
    "source_type": "html",
    "title": "Example Domain",
    "description": "This domain is for use in illustrative examples...",
    "content": "# Example Domain\n\nThis domain is for use...",
    "links": ["https://example.com/about", "https://example.com/contact"],
    "images": ["https://example.com/logo.png"],
    "metadata": {
      "title": "Example Domain",
      "lang": "en"
    }
  }
}
```

### 2. POST `/api/v1/crawl` - Website Crawling

Crawl a website starting from a URL, following internal links with BFS algorithm.

#### Request Body

```json
{
  "url": "https://example.com",
  "request": "smart",
  "return_format": "markdown",
  "metadata": true,
  "depth": 2,
  "limit": 50,
  "cache": true,
  "cache_ttl": 3600
}
```

#### Parameters

Same as `/scrape` endpoint, plus:

- **depth** (optional, integer): Maximum crawl depth (0-10, default: 2, 0 = unlimited but capped at 10)
- **limit** (optional, integer): Maximum number of pages to crawl (0-1000, default: 50, 0 = unlimited but capped at 1000)

#### Response

```json
{
  "success": true,
  "data": {
    "crawl_id": "abc123...",
    "pages": [
      {
        "success": true,
        "url": "https://example.com",
        "final_url": "https://example.com",
        "status_code": 200,
        "content_type": "text/html",
        "request_used": "http",
        "source_type": "html",
        "title": "Example Domain",
        "content": "# Example Domain\n\n...",
        "links": ["https://example.com/about"],
        "images": ["https://example.com/logo.png"]
      },
      {
        "success": true,
        "url": "https://example.com/about",
        "content": "# About Us\n\n...",
        ...
      }
    ],
    "count": 2,
    "completed": true
  }
}
```

## Features

### Smart Request Mode
The `smart` mode intelligently decides whether to use HTTP or Chrome:
- Tries HTTP first for speed
- Falls back to Chrome if HTML appears incomplete (SPA detection)
- Automatically handles redirects and cookies

### Redis Caching
- HTML pages: 1 hour TTL (configurable)
- PDF files: 24 hours TTL
- Crawl sessions: 1 hour auto-expire
- Cache key: `md5(url + options)`
- Bypass cache: Set `cache: false`

### URL Filtering (Crawl)
Automatically filters out:
- External domains (only crawls same domain)
- Auth pages: `/login`, `/logout`, `/register`, `/signin`, `/signup`, `/sign-in`, `/sign-up`, `/auth`, `/account/login`
- URL fragments (`#section`)
- Duplicate URLs

### Content Type Support
- **HTML**: Full Readability.php extraction with site-specific optimizations
- **PDF**: Text extraction via Spatie PdfToText (or base64 with `return_format: bytes`)
- **Binary** (images, videos): Base64 encoding with `return_format: bytes` or empty with `return_format: empty`

### Chrome Features
- **Infinite Scroll**: Use `scroll: 5000` (5 seconds of scrolling)
- **Wait for Element**: Use `wait_for: ".content"` to wait for dynamic content
- **Session Cookies**: Use `session: true` to persist cookies (stored in Redis)

## Examples

### Example 1: Scrape with Chrome and Infinite Scroll

```bash
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://twitter.com/search?q=laravel",
    "request": "chrome",
    "scroll": 3000,
    "wait_for": "article",
    "return_format": "markdown",
    "cache_ttl": 1800
  }'
```

### Example 2: Crawl a Documentation Site

```bash
curl -X POST http://localhost:8000/api/v1/crawl \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://laravel.com/docs/12.x",
    "depth": 3,
    "limit": 100,
    "return_format": "markdown",
    "metadata": false
  }'
```

### Example 3: Scrape PDF Document

```bash
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com/document.pdf",
    "return_format": "text"
  }'
```

### Example 4: Get Raw HTML (No Cache)

```bash
curl -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com",
    "return_format": "raw",
    "cache": false
  }'
```

## Configuration

Edit `config/scraper.php`:

```php
return [
    // Cache TTL defaults
    'cache_ttl' => env('SCRAPER_CACHE_TTL', 3600),
    'cache_ttl_pdf' => env('SCRAPER_CACHE_TTL_PDF', 86400),
    
    // Crawl defaults
    'crawl_depth' => env('SCRAPER_CRAWL_DEPTH', 2),
    'crawl_limit' => env('SCRAPER_CRAWL_LIMIT', 50),
    'crawl_session_ttl' => env('SCRAPER_CRAWL_SESSION_TTL', 3600),
];
```

## Performance Tips

1. **Use HTTP mode** for static sites (3-5x faster than Chrome)
2. **Enable caching** for repeated requests (cache hit = instant response)
3. **Set appropriate limits** for crawl to avoid memory issues
4. **Use metadata: false** to reduce payload size
5. **FrankenPHP worker mode** keeps app in memory for maximum speed

## Legacy Endpoints (Backward Compatible)

- `POST /api/scrape` - Old multi-URL scraper
- `POST /api/scrape/images` - Image extraction service
