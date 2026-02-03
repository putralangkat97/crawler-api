<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Hash;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');

        if (! $apiKey) {
            return response()->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Missing API key']], 401);
        }

        $hash = Hash::sha256($apiKey);
        $cacheKey = 'tenant:api_key:'.$hash;

        $tenant = Cache::remember($cacheKey, 300, function () use ($hash) {
            return Tenant::where('api_key_hash', $hash)->first();
        });

        if (! $tenant) {
            $fallbackKeys = array_filter(array_map('trim', explode(',', (string) env('API_KEYS', ''))));
            if (! empty($fallbackKeys) && in_array($apiKey, $fallbackKeys, true)) {
                $request->attributes->set('tenant', (object) ['tenant_id' => 'env']);

                return $next($request);
            }

            return response()->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid API key']], 401);
        }

        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
