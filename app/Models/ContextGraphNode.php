<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\DB;
class ContextGraphNode extends Model {
    use HasUuids;
    protected $fillable = [
        'type','category','title','content','attributes','tags',
        'importance','access_count','relevance_decay','last_accessed_at','embedding'
    ];
    protected $casts = ['attributes'=>'array','tags'=>'array','last_accessed_at'=>'datetime'];

    public function outEdges() { return $this->hasMany(ContextGraphEdge::class, 'source_node_id'); }
    public function inEdges() { return $this->hasMany(ContextGraphEdge::class, 'target_node_id'); }

    public static function semanticSearch(array $embedding, int $topK=10, ?string $type=null, ?string $category=null): \Illuminate\Support\Collection {
        $vec = '['.implode(',', $embedding).']';
        $query = DB::table('context_graph_nodes')
            ->selectRaw("*, 1 - (embedding <=> '{$vec}'::vector) as similarity")
            ->where(DB::raw("1 - (embedding <=> '{$vec}'::vector)"), '>=', 0.6)
            ->orderByRaw("embedding <=> '{$vec}'::vector")
            ->limit($topK);
        if ($type) $query->where('type', $type);
        if ($category) $query->where('category', $category);
        return $query->get();
    }

    public function markAccessed(): void {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }
}
