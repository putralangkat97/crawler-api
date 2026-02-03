<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TenantRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant) {
            return response()->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Tenant missing']], 401);
        }

        $path = $request->path();
        $limit = str_contains($path, '/v1/crawl')
            ? (int) config('scraper.rate_limit_crawl_per_min')
            : (int) config('scraper.rate_limit_scrape_per_min');

        $window = 60;
        $key = sprintf('ratelimit:%s:%s:%s', $tenant->tenant_id, md5($path), floor(time() / $window));

        $count = Cache::increment($key);
        Cache::put($key, $count, $window);

        if ($count > $limit) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'RATE_LIMITED', 'message' => 'Rate limit exceeded'],
            ], 429)->header('Retry-After', $window);
        }

        return $next($request);
    }
}
