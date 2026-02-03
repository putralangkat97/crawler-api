<?php

namespace App\Services;

use App\Support\Hash;
use Illuminate\Support\Str;

class ScrapeService
{
    public function __construct(
        protected HttpFetcher $httpFetcher,
        protected RendererClient $rendererClient,
        protected Extractor $extractor,
        protected R2Storage $r2Storage,
        protected SmartRouter $smartRouter
    ) {}

    public function scrape(array $urls, array $options, string $tenantId): array
    {
        $results = [];
        $maxBytes = (int) ($options['max_bytes'] ?? config('scraper.max_bytes_default'));
        $timeoutMs = (int) ($options['timeout_ms'] ?? config('scraper.scrape_http_timeout_ms'));

        foreach ($urls as $url) {
            $start = microtime(true);
            $timing = ['total' => 0, 'fetch' => null, 'render' => null, 'extract' => null];
            $result = [
                'url' => $url,
                'final_url' => null,
                'success' => false,
                'status_code' => null,
                'content_type' => null,
                'request_used' => null,
                'source_type' => 'unknown',
                'content' => '',
                'content_inline' => false,
                'content_url' => null,
                'content_expires_at' => null,
                'content_size' => null,
                'bytes_url' => null,
                'bytes_expires_at' => null,
                'bytes_size' => null,
                'bytes_sha256' => null,
                'metadata' => null,
                'links' => [],
                'images' => [],
                'timing_ms' => $timing,
                'error' => null,
            ];

            $requestType = $options['request'] ?? 'smart';
            $returnFormat = $options['return_format'] ?? 'markdown';

            $fetchStart = microtime(true);
            $httpResponse = null;

            if (in_array($requestType, ['http', 'smart'], true)) {
                $httpResponse = $this->httpFetcher->fetch($url, $timeoutMs, $maxBytes);
                $timing['fetch'] = (int) ((microtime(true) - $fetchStart) * 1000);

                if (! $httpResponse['success']) {
                    if ($requestType === 'smart' && ($httpResponse['error']['code'] ?? '') === 'HTTP_ERROR') {
                        $httpResponse = null;
                    } else {
                        $result['error'] = $httpResponse['error'] ?? ['code' => 'HTTP_ERROR', 'message' => 'Request failed'];
                        $result['timing_ms'] = $this->finalizeTiming($timing, $start);
                        $results[] = $result;

                        continue;
                    }
                }
            }

            if ($requestType === 'chrome' || ($requestType === 'smart' && $httpResponse && $this->needsRender($httpResponse, $returnFormat))) {
                if (! empty($httpResponse['body_path'])) {
                    @unlink($httpResponse['body_path']);
                }
                $renderStart = microtime(true);
                $rendered = $this->rendererClient->render($url, $options);
                $timing['render'] = (int) ((microtime(true) - $renderStart) * 1000);

                if (! $rendered['success']) {
                    $result['error'] = $rendered['error'];
                    $result['timing_ms'] = $this->finalizeTiming($timing, $start);
                    $results[] = $result;

                    continue;
                }

                $result = $this->processHtml($result, $rendered['html'], $rendered['final_url'] ?? $url, $rendered['status_code'] ?? 200, $rendered['content_type'] ?? 'text/html', 'chrome', $returnFormat, $tenantId, $options, $timing, $start);
                $results[] = $result;

                continue;
            }

            if (! $httpResponse || ! $httpResponse['success']) {
                $result['error'] = ['code' => 'HTTP_ERROR', 'message' => 'Request failed'];
                $result['timing_ms'] = $this->finalizeTiming($timing, $start);
                $results[] = $result;

                continue;
            }

            $body = file_get_contents($httpResponse['body_path']);
            @unlink($httpResponse['body_path']);
            $result = $this->processContent($result, $body, $httpResponse, $returnFormat, $tenantId, $options, $timing, $start);
            $results[] = $result;
        }

        return $results;
    }

    protected function needsRender(array $httpResponse, string $returnFormat): bool
    {
        if (! str_contains(strtolower($httpResponse['content_type'] ?? ''), 'text/html')) {
            return false;
        }
        $body = file_get_contents($httpResponse['body_path']);
        $should = $this->smartRouter->shouldRender($body);

        return $should && ! $this->smartRouter->chromeBackpressure();
    }

    protected function processContent(array $result, string $body, array $httpResponse, string $returnFormat, string $tenantId, array $options, array $timing, float $start): array
    {
        $contentType = $httpResponse['content_type'] ?? '';
        $statusCode = $httpResponse['status_code'] ?? null;
        $result['final_url'] = $httpResponse['final_url'] ?? $result['url'];
        $result['status_code'] = $statusCode;
        $result['content_type'] = $contentType;
        $result['request_used'] = 'http';

        if ($this->isPdf($contentType)) {
            return $this->processPdf($result, $body, $returnFormat, $tenantId, $options, $timing, $start);
        }

        if ($this->isBinary($contentType)) {
            return $this->processBinary($result, $body, $contentType, $returnFormat, $tenantId, $options, $timing, $start);
        }

        return $this->processHtml($result, $body, $result['final_url'], $statusCode ?? 200, $contentType, 'http', $returnFormat, $tenantId, $options, $timing, $start);
    }

    protected function processHtml(array $result, string $html, string $finalUrl, int $statusCode, string $contentType, string $requestUsed, string $returnFormat, string $tenantId, array $options, array $timing, float $start): array
    {
        if ($returnFormat === 'bytes') {
            return $this->storeBytes($result, $html, $tenantId, $options, $timing, $start, $contentType);
        }

        if ($returnFormat === 'empty') {
            $result['success'] = true;
            $result['final_url'] = $finalUrl;
            $result['status_code'] = $statusCode;
            $result['content_type'] = $contentType;
            $result['request_used'] = $requestUsed;
            $result['source_type'] = 'html';
            $result['content_inline'] = true;
            $result['content'] = '';
            $result['timing_ms'] = $this->finalizeTiming($timing, $start);

            return $result;
        }

        $extractStart = microtime(true);
        $extracted = $this->extractor->extract($html, $finalUrl, $returnFormat);
        $timing['extract'] = (int) ((microtime(true) - $extractStart) * 1000);

        $result['success'] = true;
        $result['final_url'] = $finalUrl;
        $result['status_code'] = $statusCode;
        $result['content_type'] = $contentType;
        $result['request_used'] = $requestUsed;
        $result['source_type'] = 'html';
        $result['metadata'] = ($options['metadata'] ?? false) ? $extracted['metadata'] : null;
        $result['links'] = $extracted['links'];
        $result['images'] = $extracted['images'];

        return $this->storeText($result, $extracted['content'], $tenantId, $options, $timing, $start);
    }

    protected function processPdf(array $result, string $body, string $returnFormat, string $tenantId, array $options, array $timing, float $start): array
    {
        $result['source_type'] = 'pdf';
        if ($returnFormat === 'bytes') {
            return $this->storeBytes($result, $body, $tenantId, $options, $timing, $start, 'application/pdf');
        }

        if ($returnFormat === 'empty') {
            $result['success'] = true;
            $result['content_inline'] = true;
            $result['content'] = '';
            $result['timing_ms'] = $this->finalizeTiming($timing, $start);

            return $result;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pdf_');
        file_put_contents($tmp, $body);
        $text = \Spatie\PdfToText\Pdf::getText($tmp);
        @unlink($tmp);

        $result['success'] = true;

        return $this->storeText($result, $text, $tenantId, $options, $timing, $start);
    }

    protected function processBinary(array $result, string $body, string $contentType, string $returnFormat, string $tenantId, array $options, array $timing, float $start): array
    {
        $result['source_type'] = 'binary';
        if ($returnFormat === 'bytes') {
            return $this->storeBytes($result, $body, $tenantId, $options, $timing, $start, $contentType);
        }

        if ($returnFormat === 'empty') {
            $result['success'] = true;
            $result['content_inline'] = true;
            $result['content'] = '';
            $result['timing_ms'] = $this->finalizeTiming($timing, $start);

            return $result;
        }

        $result['error'] = ['code' => 'UNSUPPORTED_FORMAT', 'message' => 'Binary content requires return_format=bytes or empty'];
        $result['timing_ms'] = $this->finalizeTiming($timing, $start);

        return $result;
    }

    protected function storeText(array $result, string $text, string $tenantId, array $options, array $timing, float $start): array
    {
        $inlineMax = (int) config('scraper.inline_text_max_bytes');
        $size = strlen($text);
        $result['content_size'] = $size;

        if ($size <= $inlineMax) {
            $result['success'] = true;
            $result['content_inline'] = true;
            $result['content'] = $text;
            $result['timing_ms'] = $this->finalizeTiming($timing, $start);

            return $result;
        }

        $sha = Hash::sha256($text);
        $key = $this->r2Storage->buildTextKey($tenantId, 'scrape', $sha);
        $this->r2Storage->put($key, $text, 'text/plain');

        $presign = $this->r2Storage->presignGet($key, (int) config('scraper.presign_ttl_seconds'));

        $result['success'] = true;
        $result['content_inline'] = false;
        $result['content'] = '';
        $result['content_url'] = $presign['url'];
        $result['content_expires_at'] = $presign['expires_at'];
        $result['timing_ms'] = $this->finalizeTiming($timing, $start);

        return $result;
    }

    protected function storeBytes(array $result, string $bytes, string $tenantId, array $options, array $timing, float $start, ?string $contentType = null): array
    {
        $sha = Hash::sha256($bytes);
        $key = $this->r2Storage->buildBytesKey($tenantId, 'scrape', $sha, $contentType);
        $this->r2Storage->put($key, $bytes, $contentType);
        $presign = $this->r2Storage->presignGet($key, (int) config('scraper.presign_ttl_seconds'));

        $result['success'] = true;
        $result['bytes_url'] = $presign['url'];
        $result['bytes_expires_at'] = $presign['expires_at'];
        $result['bytes_size'] = strlen($bytes);
        $result['bytes_sha256'] = $sha;
        $result['timing_ms'] = $this->finalizeTiming($timing, $start);

        return $result;
    }

    protected function isPdf(string $contentType): bool
    {
        return Str::contains(strtolower($contentType), 'application/pdf');
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
