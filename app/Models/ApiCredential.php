<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiCredential extends Model
{
    protected $fillable = [
        'provider',
        'env_key',
        'encrypted_value',
        'is_valid',
        'validated_at',
    ];

    protected $casts = [
        'is_valid'     => 'boolean',
        'validated_at' => 'datetime',
    ];

    protected $hidden = ['encrypted_value'];
}
