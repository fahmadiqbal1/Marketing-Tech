<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomAiPlatform extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'website_url',
        'api_base_url',
        'default_model',
        'api_key_env',
        'auth_type',
        'auth_header',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
