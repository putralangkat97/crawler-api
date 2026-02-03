<?php

namespace App\Jobs;

use App\Models\CrawlJob;
use App\Models\CrawlResult;
use App\Services\Extractor;
use App\Services\HttpFetcher;
use App\Services\PolitenessLimiter;
use App\Services\R2Storage;
use App\Services\RendererClient;
use App\Services\RobotsPolicy;
use App\Services\SmartRouter;
use App\Services\UrlNormalizer;
use App\Support\Hash;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CrawlTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(
        protected string $jobId,
        protected string $tenantId,
        protected string $url,
        protected int $depth,
        protected int $retryCount = 0
    ) {}

    public function handle(
        HttpFetcher $httpFetcher,
        RendererClient $rendererClient,
        Extractor $extractor,
        R2Storage $r2Storage,
        UrlNormalizer $normalizer,
        RobotsPolicy $robotsPolicy,
        PolitenessLimiter $politenessLimiter,
        SmartRouter $smartRouter
    ): void {
        $job = CrawlJob::find($this->jobId);
        if (! $job || $job->canceled_at) {
            return;
        }

        $params = $job->params_json;
        $maxRuntime = (int) config('scraper.max_crawl_runtime_seconds');
        if ($job->created_at && $job->created_at->diffInSeconds(now()) > $maxRuntime) {
            $job->status = 'failed';
            $job->error_json = ['code' => 'MAX_RUNTIME_EXCEEDED', 'message' => 'Crawl runtime exceeded'];
            $job->save();

            return;
        }
        $maxDepth = (int) ($params['depth'] ?? 25);
        $maxLimit = (int) ($params['limit'] ?? 0);
        $maxLimit = $maxLimit === 0 ? (int) config('scraper.max_crawl_pages_hard_cap') : min($maxLimit, (int) config('scraper.max_crawl_pages_hard_cap'));
        $requestType = $params['request'] ?? 'smart';
        $returnFormat = $params['return_format'] ?? 'markdown';
        $maxBytes = (int) ($params['max_bytes'] ?? config('scraper.max_bytes_default'));
        $timeoutMs = (int) ($params['timeout_ms'] ?? config('scraper.scrape_http_timeout_ms'));
        $sameDomainOnly = (bool) ($params['same_domain_only'] ?? true);
        $allowPatterns = $params['allow_patterns'] ?? [];
        $denyPatterns = $params['deny_patterns'] ?? [];
        $includePdf = (bool) ($params['include_pdf'] ?? true);
        $polite = $params['polite'] ?? [];

        $normalized = $normalizer->normalize($this->url);
        $host = parse_url($normalized, PHP_URL_HOST) ?? '';

        if ($sameDomainOnly) {
            $seedHost = Cache::get("crawl:{$this->jobId}:host");
            if ($seedHost && $host !== $seedHost) {
                return;
            }
        }

        if (! $robotsPolicy->isAllowed($normalized)) {
            return;
        }

        $politenessLimiter->throttle($host, $polite);

        $timing = ['total' => 0, 'fetch' => null, 'render' => null, 'extract' => null];
        $start = microtime(true);

        $result = [
            'job_id' => $this->jobId,
            'url' => $normalized,
            'normalized_url' => $normalized,
            'url_hash' => Hash::sha256($normalized),
            'final_url' => null,
            'status_code' => null,
            'content_type' => null,
            'request_used' => null,
            'source_type' => 'unknown',
            'content_r2_key' => null,
            'content_size' => null,
            'bytes_r2_key' => null,
            'bytes_size' => null,
            'bytes_sha256' => null,
            'metadata_json' => null,
            'links_json' => [],
            'images_json' => [],
            'timing_json' => [],
            'success' => false,
            'error_json' => null,
        ];

        $httpResponse = null;
        if (in_array($requestType, ['http', 'smart'], true)) {
            $fetchStart = microtime(true);
            $httpResponse = $httpFetcher->fetch($normalized, $timeoutMs, $maxBytes);
            $timing['fetch'] = (int) ((microtime(true) - $fetchStart) * 1000);

            if (! $httpResponse['success'] && $requestType === 'smart') {
                $httpResponse = null;
            }
        }

        if ($requestType === 'chrome' || ($requestType === 'smart' && $httpResponse && $smartRouter->shouldRender(file_get_contents($httpResponse['body_path'])) && ! $smartRouter->chromeBackpressure())) {
            if (! empty($httpResponse['body_path'])) {
                @unlink($httpResponse['body_path']);
            }
            $renderStart = microtime(true);
            $rendered = $rendererClient->render($normalized, $params);
            $timing['render'] = (int) ((microtime(true) - $renderStart) * 1000);

            if (! $rendered['success']) {
                $result['error_json'] = $rendered['error'];
                $result['timing_json'] = $this->finalizeTiming($timing, $start);
                $this->storeResult($result);

                return;
            }

            $html = $rendered['html'] ?? '';
            $result = $this->handleHtml($result, $html, $rendered['final_url'] ?? $normalized, $rendered['status_code'] ?? 200, $rendered['content_type'] ?? 'text/html', 'chrome', $extractor, $r2Storage, $returnFormat, $params, $timing, $start);
            $this->storeResult($result);
            $this->enqueueLinks($result, $extractor, $normalizer, $maxDepth, $maxLimit, $allowPatterns, $denyPatterns, $includePdf);

            return;
        }

        if (! $httpResponse || ! $httpResponse['success']) {
            $status = $httpResponse['status_code'] ?? null;
            $maxRetries = (int) ($polite['max_retries'] ?? 3);
            if (in_array($status, [429, 503], true) && $this->retryCount < $maxRetries) {
                $delay = (int) pow(2, $this->retryCount) * 5;
                CrawlTaskJob::dispatch($this->jobId, $this->tenantId, $this->url, $this->depth, $this->retryCount + 1)
                    ->delay($delay)
                    ->onQueue('crawl:http');

                return;
            }

            $result['error_json'] = $httpResponse['error'] ?? ['code' => 'HTTP_ERROR', 'message' => 'Request failed'];
            $result['timing_json'] = $this->finalizeTiming($timing, $start);
            $this->storeResult($result);

            return;
        }

        $body = file_get_contents($httpResponse['body_path']);
        @unlink($httpResponse['body_path']);
        $result = $this->handleContent($result, $body, $httpResponse, $extractor, $r2Storage, $returnFormat, $params, $timing, $start, $includePdf);
        $this->storeResult($result);
        $this->enqueueLinks($result, $extractor, $normalizer, $maxDepth, $maxLimit, $allowPatterns, $denyPatterns, $includePdf);
    }

    protected function handleContent(array $result, string $body, array $httpResponse, Extractor $extractor, R2Storage $r2Storage, string $returnFormat, array $params, array $timing, float $start, bool $includePdf): array
    {
        $contentType = $httpResponse['content_type'] ?? '';
        $result['final_url'] = $httpResponse['final_url'] ?? $result['url'];
        $result['status_code'] = $httpResponse['status_code'] ?? null;
        $result['content_type'] = $contentType;
        $result['request_used'] = 'http';

        if (Str::contains(strtolower($contentType), 'application/pdf')) {
            if (! $includePdf) {
                return $result;
            }

            return $this->handlePdf($result, $body, $returnFormat, $r2Storage, $params, $timing, $start);
        }

        if ($this->isBinary($contentType)) {
            return $this->handleBinary($result, $body, $contentType, $returnFormat, $r2Storage, $params, $timing, $start);
        }

        return $this->handleHtml($result, $body, $result['final_url'] ?? $result['url'], $result['status_code'] ?? 200, $contentType, 'http', $extractor, $r2Storage, $returnFormat, $params, $timing, $start);
    }

    protected function handleHtml(array $result, string $html, string $finalUrl, int $statusCode, string $contentType, string $requestUsed, Extractor $extractor, R2Storage $r2Storage, string $returnFormat, array $params, array $timing, float $start): array
    {
        $extractStart = microtime(true);
        $extracted = $extractor->extract($html, $finalUrl, $returnFormat);
        $timing['extract'] = (int) ((microtime(true) - $extractStart) * 1000);

        $result['success'] = true;
        $result['final_url'] = $finalUrl;
        $result['status_code'] = $statusCode;
        $result['content_type'] = $contentType;
        $result['request_used'] = $requestUsed;
        $result['source_type'] = 'html';
        $result['metadata_json'] = ($params['metadata'] ?? false) ? $extracted['metadata'] : null;
        $result['links_json'] = $extracted['links'];
        $result['images_json'] = $extracted['images'];

        $sha = Hash::sha256($extracted['content']);
        $key = $r2Storage->buildTextKey($this->tenantId, $this->jobId, $sha);
        $r2Storage->put($key, $extracted['content'], 'text/plain');
        $result['content_r2_key'] = $key;
        $result['content_size'] = strlen($extracted['content']);
        $result['timing_json'] = $this->finalizeTiming($timing, $start);

        return $result;
    }

    protected function handlePdf(array $result, string $body, string $returnFormat, R2Storage $r2Storage, array $params, array $timing, float $start): array
    {
        $result['source_type'] = 'pdf';
        if ($returnFormat === 'bytes') {
            return $this->storeBytes($result, $body, 'application/pdf', $r2Storage, $timing, $start);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pdf_');
        file_put_contents($tmp, $body);
        $text = \Spatie\PdfToText\Pdf::getText($tmp);
        @unlink($tmp);

        $sha = Hash::sha256($text);
        $key = $r2Storage->buildTextKey($this->tenantId, $this->jobId, $sha);
        $r2Storage->put($key, $text, 'text/plain');
        $result['content_r2_key'] = $key;
        $result['content_size'] = strlen($text);
        $result['success'] = true;
        $result['timing_json'] = $this->finalizeTiming($timing, $start);

        return $result;
    }

    protected function handleBinary(array $result, string $body, string $contentType, string $returnFormat, R2Storage $r2Storage, array $params, array $timing, float $start): array
    {
        $result['source_type'] = 'binary';
        if ($returnFormat !== 'bytes') {
            $result['error_json'] = ['code' => 'UNSUPPORTED_FORMAT', 'message' => 'Binary content requires return_format=bytes'];
            $result['timing_json'] = $this->finalizeTiming($timing, $start);

            return $result;
        }

        return $this->storeBytes($result, $body, $contentType, $r2Storage, $timing, $start);
    }

    protected function storeBytes(array $result, string $bytes, string $contentType, R2Storage $r2Storage, array $timing, float $start): array
    {
        $sha = Hash::sha256($bytes);
        $key = $r2Storage->buildBytesKey($this->tenantId, $this->jobId, $sha, $contentType);
        $r2Storage->put($key, $bytes, $contentType);
        $result['bytes_r2_key'] = $key;
        $result['bytes_size'] = strlen($bytes);
        $result['bytes_sha256'] = $sha;
        $result['success'] = true;
        $result['timing_json'] = $this->finalizeTiming($timing, $start);

        return $result;
    }

    protected function enqueueLinks(array $result, Extractor $extractor, UrlNormalizer $normalizer, int $maxDepth, int $maxLimit, array $allowPatterns, array $denyPatterns, bool $includePdf): void
    {
        if ($this->depth >= $maxDepth) {
            return;
        }

        $count = (int) Cache::get("crawl:{$this->jobId}:count", 0);
        if ($count >= $maxLimit) {
            return;
        }

        $links = $result['links_json'] ?? [];
        foreach ($links as $link) {
            $absolute = $normalizer->resolve($result['url'], $link);
            $normalized = $normalizer->normalize($absolute);
            $hash = Hash::sha256($normalized);

            if ($this->isDenied($normalized, $allowPatterns, $denyPatterns)) {
                continue;
            }

            $visitedKey = "crawl:{$this->jobId}:visited:{$hash}";
            if (Cache::has($visitedKey)) {
                continue;
            }

            Cache::put($visitedKey, true, 86400);
            $count = Cache::increment("crawl:{$this->jobId}:count");
            if ($count > $maxLimit) {
                break;
            }

            CrawlTaskJob::dispatch($this->jobId, $this->tenantId, $normalized, $this->depth + 1)
                ->onQueue('crawl:http');
        }
    }

    protected function isDenied(string $url, array $allowPatterns, array $denyPatterns): bool
    {
        foreach ($denyPatterns as $pattern) {
            if (@preg_match($pattern, $url)) {
                return true;
            }
        }

        if (! empty($allowPatterns)) {
            foreach ($allowPatterns as $pattern) {
                if (@preg_match($pattern, $url)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    protected function storeResult(array $result): void
    {
        try {
            CrawlResult::create($result);
        } catch (\Throwable) {
            // ignore duplicate url_hash
        }
    }

    protected function isBinary(string $contentType): bool
    {
        $binaryTypes = ['image/', 'video/', 'audio/', 'application/octet-stream', 'application/zip'];
        foreach ($binaryTypes as $type) {
            if (Str::contains(strtolower($contentType), $type)) {
                return true;
            }
        }

        return false;
    }

    protected function finalizeTiming(array $timing, float $start): array
    {
        $timing['total'] = (int) ((microtime(true) - $start) * 1000);

        return $timing;
    }
}
