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

];
