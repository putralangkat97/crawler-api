<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Image Filtering Options
    |--------------------------------------------------------------------------
    |
    | Configure filtering behavior for the image extraction service.
    |
    */

    'block_gifs' => env('SCRAPER_BLOCK_GIFS', true),

    'max_images_per_url' => env('SCRAPER_MAX_IMAGES', 5),

    'concurrency' => env('SCRAPER_CONCURRENCY', 5),

    'prompt_filter_enabled' => env('SCRAPER_PROMPT_FILTER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Caching Options
    |--------------------------------------------------------------------------
    |
    | Configure caching behavior for scraped pages.
    |
    */

    'cache_ttl' => env('SCRAPER_CACHE_TTL', 3600), // 1 hour default

    'cache_ttl_pdf' => env('SCRAPER_CACHE_TTL_PDF', 86400), // 24 hours for PDFs

    /*
    |--------------------------------------------------------------------------
    | Crawl Options
    |--------------------------------------------------------------------------
    |
    | Configure default limits for crawling operations.
    |
    */

    'crawl_depth' => env('SCRAPER_CRAWL_DEPTH', 2),

    'crawl_limit' => env('SCRAPER_CRAWL_LIMIT', 50),

    'crawl_session_ttl' => env('SCRAPER_CRAWL_SESSION_TTL', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | API Limits & Defaults
    |--------------------------------------------------------------------------
    */

    'scrape_http_timeout_ms' => env('SCRAPE_HTTP_TIMEOUT_MS', 8000),
    'scrape_chrome_timeout_ms' => env('SCRAPE_CHROME_TIMEOUT_MS', 20000),
    'max_bytes_default' => env('MAX_BYTES_DEFAULT', 15000000),
    'inline_text_max_bytes' => env('INLINE_TEXT_MAX_BYTES', 131072),
    'rate_limit_scrape_per_min' => env('RATE_LIMIT_SCRAPE_PER_MIN', 60),
    'rate_limit_crawl_per_min' => env('RATE_LIMIT_CRAWL_PER_MIN', 5),
    'max_scrape_urls_per_request' => env('MAX_SCRAPE_URLS_PER_REQUEST', 10),
    'max_crawl_pages_hard_cap' => env('MAX_CRAWL_PAGES_HARD_CAP', 1000),
    'max_crawl_runtime_seconds' => env('MAX_CRAWL_RUNTIME_SECONDS', 1800),
    'renderer_base_url' => env('RENDERER_BASE_URL', 'http://renderer:3001'),
    'renderer_queue_backpressure' => env('RENDERER_QUEUE_BACKPRESSURE', 1000),

    /*
    |--------------------------------------------------------------------------
    | R2 Storage
    |--------------------------------------------------------------------------
    */

    'r2_endpoint' => env('R2_ENDPOINT'),
    'r2_access_key_id' => env('R2_ACCESS_KEY_ID'),
    'r2_secret_access_key' => env('R2_SECRET_ACCESS_KEY'),
    'r2_bucket' => env('R2_BUCKET'),
    'r2_region' => env('R2_REGION', 'auto'),
    'presign_ttl_seconds' => env('PRESIGN_TTL_SECONDS', 600),
    'tmp_object_ttl_hours' => env('TMP_OBJECT_TTL_HOURS', 24),

];
