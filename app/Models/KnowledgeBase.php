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
        $vec = '['.implode(',', $embedding).']';
        $query = DB::table('knowledge_base')
            ->selectRaw("*, 1 - (embedding <=> '{$vec}'::vector) as similarity")
            ->where(DB::raw("1 - (embedding <=> '{$vec}'::vector)"), '>=', 0.65)
            ->orderByRaw("embedding <=> '{$vec}'::vector")
            ->limit($topK);
        if ($category) $query->where('category', $category);
        return $query->get();
    }
}
