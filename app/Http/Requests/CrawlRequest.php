<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrawlRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'url' => 'required|url',
            'limit' => 'sometimes|integer|min:0|max:1000',
            'depth' => 'sometimes|integer|min:0|max:100',
            'request' => 'sometimes|string|in:http,chrome,smart',
            'return_format' => 'sometimes|string|in:markdown,commonmark,raw,text,xml,bytes,empty',
            'metadata' => 'sometimes|boolean',
            'session' => 'sometimes|boolean',
            'scroll' => 'sometimes|integer|min:0|max:30000',
            'wait_for' => 'sometimes|array',
            'timeout_ms' => 'sometimes|integer|min:1000|max:120000',
            'max_bytes' => 'sometimes|integer|min:1024|max:50000000',
            'same_domain_only' => 'sometimes|boolean',
            'allow_patterns' => 'sometimes|array',
            'deny_patterns' => 'sometimes|array',
            'include_pdf' => 'sometimes|boolean',
            'polite' => 'sometimes|array',
            'polite.concurrency' => 'sometimes|integer|min:1|max:20',
            'polite.per_host_delay_ms' => 'sometimes|integer|min:100|max:10000',
            'polite.jitter_ratio' => 'sometimes|numeric|min:0|max:1',
            'polite.max_errors' => 'sometimes|integer|min:1|max:200',
            'polite.max_retries' => 'sometimes|integer|min:0|max:10',
        ];
    }
}
