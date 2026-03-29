<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KnowledgeBase extends Model
{
    use HasUuids, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new BusinessScope());
        static::creating(function (self $model) {
            if (auth()->check() && auth()->user()->business_id) {
                $model->business_id ??= auth()->user()->business_id;
            }
        });
    }

    protected $table = 'knowledge_base';

    protected $fillable = ['title', 'content', 'category', 'tags', 'source', 'embedding', 'chunk_index', 'parent_id', 'content_hash', 'index_tree', 'node_id', 'business_id'];

    protected $casts = ['tags' => 'array', 'index_tree' => 'array'];

    public static function semanticSearch(
        array $embedding, int $topK = 5,
        ?string $category = null, array $categories = []
    ): Collection {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return collect();
        }

        // Sanitize: cast every value to float to prevent injection via non-numeric values.
        // PDO does not support parameterized pgvector literals, so we sanitize explicitly.
        $safeFloats = array_map('floatval', $embedding);
        $vec = '['.implode(',', $safeFloats).']';

        $query = DB::table('knowledge_base')
            ->selectRaw('*, 1 - (embedding <=> ?::vector) as similarity', [$vec])
            ->whereRaw('1 - (embedding <=> ?::vector) >= 0.65', [$vec])
            ->orderByRaw('embedding <=> ?::vector', [$vec])
            ->limit($topK);
        if (!empty($categories)) {
            $query->whereIn('category', $categories);
        } elseif ($category) {
            $query->where('category', $category);
        }

        return $query->get();
    }
}
