<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrawlRequest;
use App\Models\CrawlJob;
use App\Models\CrawlResult;
use App\Services\CrawlService;
use App\Services\R2Storage;
use App\Support\CursorPagination;
use Illuminate\Http\Request;

class CrawlController extends Controller
{
    public function store(CrawlRequest $request, CrawlService $crawlService)
    {
        $validated = $request->validated();
        $options = [
            'url' => $validated['url'],
            'limit' => $validated['limit'] ?? 0,
            'depth' => $validated['depth'] ?? 25,
            'request' => $validated['request'] ?? 'smart',
            'return_format' => $validated['return_format'] ?? 'markdown',
            'metadata' => $validated['metadata'] ?? false,
            'session' => $validated['session'] ?? false,
            'scroll' => $validated['scroll'] ?? 0,
            'wait_for' => $validated['wait_for'] ?? [],
            'timeout_ms' => $validated['timeout_ms'] ?? config('scraper.scrape_http_timeout_ms'),
            'max_bytes' => $validated['max_bytes'] ?? config('scraper.max_bytes_default'),
            'same_domain_only' => $validated['same_domain_only'] ?? true,
            'allow_patterns' => $validated['allow_patterns'] ?? [],
            'deny_patterns' => $validated['deny_patterns'] ?? [],
            'include_pdf' => $validated['include_pdf'] ?? true,
            'polite' => $validated['polite'] ?? [
                'concurrency' => 3,
                'per_host_delay_ms' => 1200,
                'jitter_ratio' => 0.3,
                'max_errors' => 25,
                'max_retries' => 3,
            ],
        ];

        $tenant = $request->attributes->get('tenant');
        $jobId = $crawlService->start($options, $tenant->tenant_id, $request->header('Idempotency-Key'));

        return response()->json([
            'success' => true,
            'job_id' => $jobId,
        ]);
    }

    public function show(string $jobId)
    {
        $job = CrawlJob::findOrFail($jobId);

        return response()->json([
            'success' => true,
            'data' => [
                'job_id' => $job->job_id,
                'status' => $job->status,
                'created_at' => $job->created_at?->toISOString(),
                'updated_at' => $job->updated_at?->toISOString(),
                'canceled_at' => $job->canceled_at?->toISOString(),
                'error' => $job->error_json,
            ],
        ]);
    }

    public function results(Request $request, string $jobId, R2Storage $r2Storage)
    {
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $cursor = $request->query('cursor');

        [$items, $nextCursor] = CursorPagination::apply(
            CrawlResult::where('job_id', $jobId),
            $cursor,
            $limit
        );

        $ttl = (int) config('scraper.presign_ttl_seconds');
        $data = $items->map(function (CrawlResult $result) use ($r2Storage, $ttl) {
            $content = null;
            $bytes = null;

            if ($result->content_r2_key) {
                $content = $r2Storage->presignGet($result->content_r2_key, $ttl);
            }
            if ($result->bytes_r2_key) {
                $bytes = $r2Storage->presignGet($result->bytes_r2_key, $ttl);
            }

            return [
                'url' => $result->url,
                'final_url' => $result->final_url,
                'success' => $result->success,
                'status_code' => $result->status_code,
                'content_type' => $result->content_type,
                'request_used' => $result->request_used,
                'source_type' => $result->source_type,
                'content' => '',
                'content_inline' => false,
                'content_url' => $content['url'] ?? null,
                'content_expires_at' => $content['expires_at'] ?? null,
                'content_size' => $result->content_size,
                'bytes_url' => $bytes['url'] ?? null,
                'bytes_expires_at' => $bytes['expires_at'] ?? null,
                'bytes_size' => $result->bytes_size,
                'bytes_sha256' => $result->bytes_sha256,
                'metadata' => $result->metadata_json,
                'links' => $result->links_json ?? [],
                'images' => $result->images_json ?? [],
                'timing_ms' => $result->timing_json,
                'error' => $result->error_json,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'next_cursor' => $nextCursor,
        ]);
    }

    public function destroy(string $jobId)
    {
        $job = CrawlJob::findOrFail($jobId);
        $job->canceled_at = now();
        $job->status = 'canceled';
        $job->save();

        return response()->json(['success' => true]);
    }
}
