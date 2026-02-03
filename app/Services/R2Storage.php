<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class R2Storage
{
    protected S3Client $client;

    public function __construct()
    {
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => config('scraper.r2_region', 'auto'),
            'endpoint' => config('scraper.r2_endpoint'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => config('scraper.r2_access_key_id'),
                'secret' => config('scraper.r2_secret_access_key'),
            ],
        ]);
    }

    public function put(string $key, $contents, ?string $contentType = null): void
    {
        Storage::disk('r2')->put($key, $contents, [
            'visibility' => 'private',
            'ContentType' => $contentType,
        ]);
    }

    public function putStream(string $key, $stream, ?string $contentType = null): void
    {
        Storage::disk('r2')->put($key, $stream, [
            'visibility' => 'private',
            'ContentType' => $contentType,
        ]);
    }

    public function presignGet(string $key, int $ttlSeconds): array
    {
        $bucket = config('scraper.r2_bucket');
        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        $request = $this->client->createPresignedRequest($cmd, "+{$ttlSeconds} seconds");

        return [
            'url' => (string) $request->getUri(),
            'expires_at' => now()->addSeconds($ttlSeconds)->toISOString(),
        ];
    }

    public function buildTextKey(string $tenantId, string $jobId, string $sha256): string
    {
        return sprintf('tmp/%s/%s/content/%s.txt', $tenantId, $jobId, $sha256);
    }

    public function buildBytesKey(string $tenantId, string $jobId, string $sha256, ?string $contentType = null): string
    {
        $ext = 'bin';
        if ($contentType && Str::contains(strtolower($contentType), 'pdf')) {
            $ext = 'pdf';
        }

        return sprintf('tmp/%s/%s/bytes/%s.%s', $tenantId, $jobId, $sha256, $ext);
    }
}
