<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialCredential extends Model
{
    protected $fillable = [
        'platform',
        'client_id',
        'client_secret',
        'extra_config',
        'is_active',
        'last_tested_at',
        'last_test_error',
    ];

    protected $casts = [
        'client_id'      => 'encrypted',
        'client_secret'  => 'encrypted',
        'extra_config'   => 'array',
        'is_active'      => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = [
        'client_id',
        'client_secret',
    ];

    public static function forPlatform(string $platform): ?self
    {
        return static::where('platform', $platform)->first();
    }
}
