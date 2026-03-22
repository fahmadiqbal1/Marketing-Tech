<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'platform', 'handle', 'display_name', 'platform_user_id',
        'access_token', 'refresh_token', 'token_expires_at',
        'is_connected', 'follower_count', 'avg_engagement_rate',
        'metadata', 'last_error', 'last_synced_at',
    ];

    protected $hidden = [
        'access_token', 'refresh_token',
    ];

    protected $casts = [
        'metadata'          => 'array',
        'token_expires_at'  => 'datetime',
        'last_synced_at'    => 'datetime',
        'is_connected'      => 'boolean',
        'follower_count'    => 'integer',
        'avg_engagement_rate' => 'float',
    ];

    public function isTokenExpiringSoon(): bool
    {
        if (! $this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->lt(now()->addHours(24));
    }

    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->isPast();
    }

    public function scopeConnected($query)
    {
        return $query->where('is_connected', true);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeTokenExpiringSoon($query)
    {
        return $query->where('is_connected', true)
            ->where('token_expires_at', '<', now()->addHours(24));
    }
}
