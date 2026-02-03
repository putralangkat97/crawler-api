<?php

namespace App\Jobs;

use App\Models\CrawlJob;
use App\Services\UrlNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class CrawlStartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(protected string $jobId, protected string $tenantId) {}

    public function handle(UrlNormalizer $normalizer): void
    {
        $job = CrawlJob::find($this->jobId);
        if (! $job || $job->canceled_at) {
            return;
        }

        $params = $job->params_json;
        $startUrl = $params['url'];
        $normalized = $normalizer->normalize($startUrl);
        $host = parse_url($normalized, PHP_URL_HOST) ?? '';

        Cache::put("crawl:{$this->jobId}:count", 0, 86400);
        Cache::put("crawl:{$this->jobId}:host", $host, 86400);

        $job->status = 'running';
        $job->save();

        CrawlTaskJob::dispatch($this->jobId, $this->tenantId, $normalized, 0)
            ->onQueue($this->queueForRequest($params));
    }

    protected function queueForRequest(array $params): string
    {
        $request = $params['request'] ?? 'smart';

        return $request === 'chrome' ? 'crawl:chrome' : 'crawl:http';
    }
}
