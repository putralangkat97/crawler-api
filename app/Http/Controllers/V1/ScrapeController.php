<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScrapeRequest;
use App\Services\ScrapeService;

class ScrapeController extends Controller
{
    public function __invoke(ScrapeRequest $request, ScrapeService $scrapeService)
    {
        $validated = $request->validated();
        $options = [
            'request' => $validated['request'] ?? 'smart',
            'return_format' => $validated['return_format'] ?? 'markdown',
            'metadata' => $validated['metadata'] ?? false,
            'scroll' => $validated['scroll'] ?? 0,
            'wait_for' => $validated['wait_for'] ?? [],
            'session' => $validated['session'] ?? false,
            'timeout_ms' => $validated['timeout_ms'] ?? config('scraper.scrape_http_timeout_ms'),
            'max_bytes' => $validated['max_bytes'] ?? config('scraper.max_bytes_default'),
        ];

        if ($options['request'] === 'http' && (! empty($options['scroll']) || ! empty($options['wait_for']))) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_REQUEST', 'message' => 'scroll and wait_for require chrome'],
            ], 422);
        }

        $urls = is_array($validated['url']) ? $validated['url'] : [$validated['url']];
        $urls = array_slice($urls, 0, (int) config('scraper.max_scrape_urls_per_request'));

        $tenant = $request->attributes->get('tenant');
        $results = $scrapeService->scrape($urls, $options, $tenant->tenant_id);

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }
}
