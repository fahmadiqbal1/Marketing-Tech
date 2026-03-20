<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'severity',
        'source',
        'entity_id',
        'entity_type',
        'message',
        'payload',
        'chat_id',
        'notified',
        'occurred_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'notified'    => 'boolean',
        'occurred_at' => 'datetime',
    ];

    public static function emit(
        string  $type,
        string  $message,
        string  $severity    = 'info',
        ?string $source      = 'app',
        ?string $entityId    = null,
        ?string $entityType  = null,
        array   $payload     = [],
        ?int    $chatId      = null,
    ): void {
        try {
            static::create([
                'event_type'  => $type,
                'severity'    => $severity,
                'source'      => $source,
                'entity_id'   => $entityId,
                'entity_type' => $entityType,
                'message'     => $message,
                'payload'     => $payload,
                'chat_id'     => $chatId,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("SystemEvent::emit failed: " . $e->getMessage());
        }
    }
}
