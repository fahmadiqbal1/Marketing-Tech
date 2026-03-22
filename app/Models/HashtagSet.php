<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HashtagSet extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'platform', 'niche', 'tags', 'reach_tier', 'usage_count',
    ];

    protected $casts = [
        'tags'        => 'array',
        'usage_count' => 'integer',
    ];

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeForNiche($query, string $niche)
    {
        return $query->where('niche', $niche);
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }
}
