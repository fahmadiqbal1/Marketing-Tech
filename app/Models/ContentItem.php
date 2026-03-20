<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class ContentItem extends Model {
    use HasUuids;
    protected $fillable = ['title','body','type','platform','status','tags','scheduled_at','word_count','agent_run_id'];
    protected $casts = ['tags'=>'array','scheduled_at'=>'datetime'];
}
