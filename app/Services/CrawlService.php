<?php

namespace App\Services;

use App\Jobs\CrawlStartJob;
use App\Models\CrawlJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CrawlService
{
    public function start(array $payload, string $tenantId, ?string $idempotencyKey = null): string
    {
        if ($idempotencyKey) {
            $key = 'idempotency:'.$tenantId.':'.hash('sha256', json_encode($payload)).':'.$idempotencyKey;
            $existing = Cache::get($key);
            if ($existing) {
                return $existing;
            }
        }

        $jobId = 'crawl_'.Str::uuid();
        CrawlJob::create([
            'job_id' => $jobId,
            'tenant_id' => $tenantId,
            'status' => 'queued',
            'params_json' => $payload,
        ]);

        CrawlStartJob::dispatch($jobId, $tenantId)->onQueue('crawl:seed');

        if ($idempotencyKey) {
            Cache::put($key ?? '', $jobId, 86400);
        }

        return $jobId;
    }
}
