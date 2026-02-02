# AI Coding Instructions for Scraper API

## Big Picture
- Laravel 12 API focused on scraping and image extraction. Core logic lives in services under app/Services: ImageExtractor for images and SpiderCrawler for page/crawl flows.
- Routes in routes/api.php map to invokable controllers in app/Http/Controllers and app/Http/Controllers/V1. Controllers validate input, set defaults, then call services.
- config/scraper.php centralizes tunables (concurrency, cache TTLs, crawl depth/limit, GIF blocking, prompt filtering).

## Key Services & Data Flow
- Image extraction: ImageExtractor::extractFromUrls() uses Http::pool() concurrency, pulls og/twitter images + img tags, applies score/filters, returns top N (default 5). See app/Services/ImageExtractor.php.
- Scraping/crawling: SpiderCrawler::scrapePage() handles cache, request type (http/smart/chrome), return_format, metadata; SpiderCrawler::crawlSite() does BFS using Cache-backed queue/visited and domain filtering. See app/Services/SpiderCrawler.php.
- V1 endpoints return { success: true, data: result } for single scrape/crawl; legacy endpoints return { success, data, count }. See routes/api.php and controllers.

## Request/Response Conventions
- Validation happens inside each controller; keep controllers thin and invokable (public function __invoke(Request $request, Service $service)).
- request options include request (http|smart|chrome), return_format (markdown|commonmark|raw|text|bytes|empty), metadata, scroll, wait_for, session, cache/cache_ttl.
- For relative URLs, always resolve via UriResolver::resolve() as used in services.

## Caching & Sessions
- SpiderCrawler uses Cache for per-URL results (cache_ttl, cache_ttl_pdf) and per-crawl queue/visited sets; session cookies are stored in Cache when session=true.

## External Dependencies
- Symfony DomCrawler + UriResolver for parsing/URL resolution.
- Browsershot for JS rendering; PdfToText for PDF extraction. Ensure these remain the JS/PDF paths for SpiderCrawler.
- Laravel Octane with FrankenPHP is the production-like runtime (config/octane.php).

## Workflows
- Use composer dev to run server/queue/pail/vite together; alternative: php artisan serve or php artisan octane:start.
- Tests use Pest (tests/Feature and tests/Unit). Run composer test or php artisan test.
- Shell alias pa is used for artisan commands (pa migrate, pa config:clear).

## Style & Patterns
- Follow PSR-12/Laravel conventions; format with ./vendor/bin/pint.
- Keep business logic in services; avoid adding heavy logic in controllers.
