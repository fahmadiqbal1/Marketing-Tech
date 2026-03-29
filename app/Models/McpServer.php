<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class McpServer extends Model
{
    use HasUuids;

    protected $fillable = [
        'business_id', 'name', 'transport', 'command', 'url',
        'args', 'env_vars', 'is_active', 'capabilities', 'last_synced_at',
    ];

    protected $casts = [
        'args'           => 'array',
        'env_vars'       => 'array',
        'capabilities'   => 'array',
        'is_active'      => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
