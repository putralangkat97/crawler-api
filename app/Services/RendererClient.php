<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RendererClient
{
    public function render(string $url, array $options): array
    {
        if ($this->isBreakerOpen()) {
            return ['success' => false, 'error' => ['code' => 'RENDERER_UNAVAILABLE', 'message' => 'Renderer circuit open']];
        }

        $baseUrl = rtrim(config('scraper.renderer_base_url'), '/');
        $timeoutMs = (int) ($options['timeout_ms'] ?? config('scraper.scrape_chrome_timeout_ms'));

        try {
            $response = Http::timeout(max(1, (int) ceil($timeoutMs / 1000)))
                ->post($baseUrl.'/render', [
                    'url' => $url,
                    'wait_for' => $options['wait_for'] ?? [],
                    'scroll' => $options['scroll'] ?? 0,
                    'timeout_ms' => $timeoutMs,
                ]);

            /** @disregard P1013 */
            if (! $response->successful()) {
                $this->recordFailure();

                return ['success' => false, 'error' => ['code' => 'RENDERER_ERROR', 'message' => 'Renderer failed']];
            }

            $this->recordSuccess();

            /** @disregard P1013 */
            return [
                'success' => true,
                'final_url' => $response->json('final_url'),
                'status_code' => $response->json('status_code'),
                'content_type' => $response->json('content_type'),
                'html' => $response->json('html'),
                'timing_ms' => $response->json('timing_ms'),
                'request_used' => 'chrome',
            ];
        } catch (\Throwable $e) {
            $this->recordFailure();

            return ['success' => false, 'error' => ['code' => 'RENDERER_TIMEOUT', 'message' => 'Renderer timeout']];
        }
    }

    protected function recordFailure(): void
    {
        $key = 'renderer:failures';
        $count = Cache::increment($key);
        Cache::put($key, $count, 60);
        if ($count >= 5) {
            Cache::put('renderer:circuit_open', true, 90);
        }
    }

    protected function recordSuccess(): void
    {
        Cache::forget('renderer:failures');
    }

    protected function isBreakerOpen(): bool
    {
        return Cache::get('renderer:circuit_open', false) === true;
    }
}
