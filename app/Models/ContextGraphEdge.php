<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ContextGraphEdge extends Model {
    use HasUuids;
    protected $fillable = ['source_node_id','target_node_id','relation_type','strength','description','metadata'];
    protected $casts = ['metadata'=>'array','strength'=>'float'];
    public function source(): BelongsTo { return $this->belongsTo(ContextGraphNode::class, 'source_node_id'); }
    public function target(): BelongsTo { return $this->belongsTo(ContextGraphNode::class, 'target_node_id'); }
}
