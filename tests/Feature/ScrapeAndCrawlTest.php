<?php

test('POST /v1/scrape returns content with markdown format', function () {
    $response = $this->post('/api/v1/scrape', [
        'url' => 'https://example.com',
        'return_format' => 'markdown',
        'metadata' => true,
    ]);

    $response->assertStatus(200);
    expect($response->json('success'))->toBeTrue();
    expect($response->json('data.0.url'))->toBe('https://example.com');
    expect($response->json('data.0.success'))->toBeTrue();
    expect($response->json('data.0.content'))->toBeString();
    expect($response->json('data.0.metadata'))->toBeArray();
});

test('POST /v1/scrape with multiple URLs returns batch results', function () {
    $response = $this->post('/api/v1/scrape', [
        'url' => [
            'https://example.com',
            'https://example.org',
        ],
        'return_format' => 'markdown',
    ]);

    $response->assertStatus(200);
    expect($response->json('success'))->toBeTrue();
    expect($response->json('data'))->toHaveLength(2);
    expect($response->json('data.0.url'))->toBe('https://example.com');
    expect($response->json('data.1.url'))->toBe('https://example.org');
});

test('POST /v1/scrape rejects invalid URL', function () {
    $response = $this->post('/api/v1/scrape', [
        'url' => 'not-a-valid-url',
    ]);

    $response->assertStatus(422);
    expect($response->json('success'))->toBeFalse();
});

test('POST /v1/scrape with chrome request requires valid timeout', function () {
    $response = $this->post('/api/v1/scrape', [
        'url' => 'https://example.com',
        'request' => 'chrome',
        'timeout_ms' => 500, // Too low
    ]);

    $response->assertStatus(422);
});

test('POST /v1/scrape with http request rejects scroll and wait_for', function () {
    $response = $this->post('/api/v1/scrape', [
        'url' => 'https://example.com',
        'request' => 'http',
        'scroll' => 1000,
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('INVALID_REQUEST');
});

test('POST /v1/scrape respects return_format parameter', function () {
    $formats = ['markdown', 'raw', 'text', 'commonmark'];

    foreach ($formats as $format) {
        $response = $this->post('/api/v1/scrape', [
            'url' => 'https://example.com',
            'return_format' => $format,
        ]);

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.0.content'))->toBeString();
    }
});

test('POST /v1/crawl creates async job', function () {
    $response = $this->post('/api/v1/crawl', [
        'url' => 'https://example.com',
        'limit' => 10,
        'depth' => 2,
    ]);

    $response->assertStatus(200);
    expect($response->json('success'))->toBeTrue();
    expect($response->json('job_id'))->toMatch('/^crawl_/');
});

test('POST /v1/crawl validates depth and limit', function () {
    $response = $this->post('/api/v1/crawl', [
        'url' => 'https://example.com',
        'depth' => 500, // Exceeds max
    ]);

    $response->assertStatus(422);
});

test('GET /v1/crawl/{job_id} returns job status', function () {
    $job = CrawlJob::create([
        'job_id' => 'crawl_test_'.now()->timestamp,
        'tenant_id' => 'test_tenant',
        'status' => 'running',
        'params_json' => ['url' => 'https://example.com'],
    ]);

    $response = $this->get("/api/v1/crawl/{$job->job_id}");

    $response->assertStatus(200);
    expect($response->json('success'))->toBeTrue();
    expect($response->json('data.status'))->toBe('running');
});

test('DELETE /v1/crawl/{job_id} cancels job', function () {
    $job = CrawlJob::create([
        'job_id' => 'crawl_test_'.now()->timestamp,
        'tenant_id' => 'test_tenant',
        'status' => 'running',
        'params_json' => ['url' => 'https://example.com'],
    ]);

    $response = $this->delete("/api/v1/crawl/{$job->job_id}");

    $response->assertStatus(200);
    expect($response->json('success'))->toBeTrue();

    $job->refresh();
    expect($job->canceled_at)->not->toBeNull();
});

test('GET /health returns database and cache status', function () {
    $response = $this->get('/health');

    $response->assertStatus(200);
    expect($response->json('status'))->toBe('ok');
    expect($response->json('database'))->toBe('connected');
    expect($response->json('cache'))->toBe('connected');
});

test('GET /ready checks queue and renderer availability', function () {
    $response = $this->get('/ready');

    $response->assertStatus(200);
    expect($response->json('ready'))->toBeBoolean();
});
