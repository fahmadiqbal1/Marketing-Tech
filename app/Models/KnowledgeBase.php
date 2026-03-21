<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\DB;
class KnowledgeBase extends Model {
    use HasUuids;
    protected $table = 'knowledge_base';
    protected $fillable = ['title','content','category','tags','source','embedding','chunk_index','parent_id','content_hash'];
    protected $casts = ['tags'=>'array'];
    public static function semanticSearch(array $embedding, int $topK=5, ?string $category=null): \Illuminate\Support\Collection {
        // Sanitize: cast every value to float to prevent injection via non-numeric values.
        // PDO does not support parameterized pgvector literals, so we sanitize explicitly.
        $safeFloats = array_map('floatval', $embedding);
        $vec = '[' . implode(',', $safeFloats) . ']';

        $query = DB::table('knowledge_base')
            ->selectRaw("*, 1 - (embedding <=> ?::vector) as similarity", [$vec])
            ->whereRaw("1 - (embedding <=> ?::vector) >= 0.65", [$vec])
            ->orderByRaw("embedding <=> ?::vector", [$vec])
            ->limit($topK);
        if ($category) $query->where('category', $category);
        return $query->get();
    }
}
