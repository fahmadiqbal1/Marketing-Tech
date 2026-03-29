<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HashtagSet extends Model
{
    use HasUuids;

    protected $fillable = [
        'business_id', 'name', 'platform', 'niche', 'tags', 'reach_tier', 'usage_count',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BusinessScope());
        static::creating(function (self $model) {
            if (auth()->check() && auth()->user()->business_id) {
                $model->business_id ??= auth()->user()->business_id;
            }
        });
    }

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
