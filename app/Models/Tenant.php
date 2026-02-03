<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = [
        'tenant_id',
        'api_key_hash',
    ];

    public $timestamps = true;
}
