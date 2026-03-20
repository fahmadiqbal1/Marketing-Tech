<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AgentJob extends Model {
    use HasUuids;
    protected $fillable = [
        'workflow_id','agent_type','agent_class','instruction','short_description','status',
        'result','error_message','steps_taken','last_tool','metadata',
        'chat_id','user_id','started_at','completed_at',
    ];
    protected $casts = [
        'metadata'     => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];
}
