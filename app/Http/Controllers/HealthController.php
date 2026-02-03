<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HealthController extends Controller
{
    public function health()
    {
        try {
            DB::select('select 1');
            Cache::put('healthcheck', 'ok', 10);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'fail', 'error' => 'db_or_cache'], 503);
        }

        return response()->json(['status' => 'ok']);
    }

    public function ready()
    {
        $rendererUrl = rtrim(config('scraper.renderer_base_url'), '/').'/render';
        try {
            Http::timeout(2)->post($rendererUrl, ['url' => 'https://example.com', 'timeout_ms' => 1000]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'fail', 'error' => 'renderer'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
