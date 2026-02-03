<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HttpFetcher
{
    protected array $headers = [
        'User-Agent' => 'ScraperAPI/2.0 (+https://example.com)',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
        'Cache-Control' => 'no-cache',
        'Pragma' => 'no-cache',
        'Connection' => 'keep-alive',
    ];

    public function __construct(protected SsrfGuard $ssrfGuard) {}

    public function fetch(string $url, int $timeoutMs, int $maxBytes): array
    {
        if ($error = $this->ssrfGuard->validate($url)) {
            return ['success' => false, 'error' => $error];
        }

        $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));

        $response = Http::withHeaders($this->headers)
            ->withOptions(['stream' => true])
            ->timeout($timeoutSeconds)
            ->connectTimeout(10)
            ->get($url);

        /** @disregard P1013 */
        $status = $response->status();
        /** @disregard P1013 */
        $contentType = $response->header('Content-Type') ?? '';

        /** @disregard P1013 */
        if (! $response->successful()) {
            /** @disregard P1013 */
            return [
                'success' => false,
                'status_code' => $status,
                'content_type' => $contentType,
                'error' => ['code' => 'HTTP_ERROR', 'message' => $status.': '.$response->reason()],
            ];
        }

        /** @disregard P1013 */
        $psr = $response->toPsrResponse();
        $stream = $psr->getBody();
        $tmpPath = tempnam(sys_get_temp_dir(), 'scrape_');
        $handle = fopen($tmpPath, 'wb');
        $bytes = 0;

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                fclose($handle);
                @unlink($tmpPath);

                return [
                    'success' => false,
                    'status_code' => $status,
                    'content_type' => $contentType,
                    'error' => ['code' => 'MAX_BYTES_EXCEEDED', 'message' => 'Response exceeded max_bytes'],
                ];
            }
            fwrite($handle, $chunk);
        }

        fclose($handle);

        return [
            'success' => true,
            'status_code' => $status,
            'content_type' => $contentType,
            'final_url' => (string) $psr->getHeaderLine('X-Guzzle-Effective-Url') ?: $url,
            'body_path' => $tmpPath,
            'bytes' => $bytes,
            'request_used' => 'http',
        ];
    }

    public function looksLikeHtml(string $body): bool
    {
        $lower = Str::lower($body);

        return str_contains($lower, '<html') || str_contains($lower, '<body') || str_contains($lower, '<!doctype html');
    }
}
