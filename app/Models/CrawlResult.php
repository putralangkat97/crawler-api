<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrawlResult extends Model
{
    protected $fillable = [
        'job_id',
        'url',
        'normalized_url',
        'url_hash',
        'final_url',
        'status_code',
        'content_type',
        'request_used',
        'source_type',
        'content_r2_key',
        'content_size',
        'bytes_r2_key',
        'bytes_size',
        'bytes_sha256',
        'metadata_json',
        'links_json',
        'images_json',
        'timing_json',
        'success',
        'error_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'links_json' => 'array',
        'images_json' => 'array',
        'timing_json' => 'array',
        'error_json' => 'array',
        'success' => 'boolean',
    ];
}
