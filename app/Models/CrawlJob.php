<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrawlJob extends Model
{
    protected $primaryKey = 'job_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'job_id',
        'tenant_id',
        'status',
        'params_json',
        'canceled_at',
        'error_json',
    ];

    protected $casts = [
        'params_json' => 'array',
        'error_json' => 'array',
        'canceled_at' => 'datetime',
    ];
}
