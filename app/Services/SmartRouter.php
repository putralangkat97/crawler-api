<?php

namespace App\Services;

use Illuminate\Support\Facades\Queue;

class SmartRouter
{
    public function shouldRender(string $html, array $options = []): bool
    {
        $trimmed = trim($html);
        if ($trimmed === '' || strlen($trimmed) < 200) {
            return true;
        }

        if (! str_contains(strtolower($trimmed), '<html')) {
            return true;
        }

        if (preg_match('/<script[^>]*>[^<]*window\.__INITIAL_STATE__|__NEXT_DATA__|__NUXT__|data-reactroot/i', $trimmed)) {
            return true;
        }

        return false;
    }

    public function chromeBackpressure(): bool
    {
        $threshold = (int) config('scraper.renderer_queue_backpressure', 1000);
        $size = Queue::size('crawl:chrome');

        return $size !== null && $size >= $threshold;
    }
}
