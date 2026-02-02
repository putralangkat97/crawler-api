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

];
