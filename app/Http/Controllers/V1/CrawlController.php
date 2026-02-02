<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\SpiderCrawler;
use Illuminate\Http\Request;

class CrawlController extends Controller
{
    public function __invoke(Request $request, SpiderCrawler $crawler)
    {
        $validated = $request->validate([
            'url' => 'required|string|url',
            'request' => 'sometimes|string|in:http,chrome,smart',
            'return_format' => 'sometimes|string|in:markdown,commonmark,raw,text,bytes,empty',
            'metadata' => 'sometimes|boolean',
            'depth' => 'sometimes|integer|min:0|max:10',
            'limit' => 'sometimes|integer|min:0|max:1000',
            'scroll' => 'sometimes|integer|min:0|max:30000',
            'wait_for' => 'sometimes|string|max:500',
            'session' => 'sometimes|boolean',
            'cache' => 'sometimes|boolean',
            'cache_ttl' => 'sometimes|integer|min:0|max:86400',
        ]);

        // Set defaults
        $options = [
            'request' => $validated['request'] ?? 'smart',
            'return_format' => $validated['return_format'] ?? 'markdown',
            'metadata' => $validated['metadata'] ?? true,
            'depth' => $validated['depth'] ?? 2,
            'limit' => $validated['limit'] ?? 50,
            'scroll' => $validated['scroll'] ?? null,
            'wait_for' => $validated['wait_for'] ?? null,
            'session' => $validated['session'] ?? false,
            'cache' => $validated['cache'] ?? true,
            'cache_ttl' => $validated['cache_ttl'] ?? config('scraper.cache_ttl', 3600),
        ];

        $result = $crawler->crawlSite($validated['url'], $options);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
