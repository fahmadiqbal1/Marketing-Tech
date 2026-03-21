<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPerformance extends Model
{
    public $timestamps = false;

    protected $table = 'content_performance';

    protected $fillable = [
        'content_variation_id',
        'impressions',
        'clicks',
        'conversions',
        'ctr',
        'score',
        'source',
        'recorded_at',
    ];

    protected $casts = [
        'impressions'  => 'integer',
        'clicks'       => 'integer',
        'conversions'  => 'integer',
        'ctr'          => 'float',
        'score'        => 'float',
        'recorded_at'  => 'datetime',
    ];

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ContentVariation::class, 'content_variation_id');
    }

    /**
     * Compute score from raw metrics before saving.
     */
    public static function computeScore(int $impressions, int $clicks, int $conversions): float
    {
        return round($conversions * 10 + $clicks * 2 + $impressions * 0.1, 4);
    }
}
