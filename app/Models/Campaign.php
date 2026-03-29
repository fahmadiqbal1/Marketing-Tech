<?php
namespace App\Models;
use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
class Campaign extends Model {
    use HasUuids, SoftDeletes;
    protected $fillable = [
        'business_id',
        'workflow_id','name','type','status','subject','body','audience','list_id',
        'ab_variants','winning_variant','send_count','open_count','click_count',
        'conversion_count','unsubscribe_count','revenue_attributed','performance_data',
        'experiment_id','created_by_agent','agent_run_id','schedule_at','sent_at'
    ];
    protected static function booted(): void {
        static::addGlobalScope(new BusinessScope());
        static::creating(function (self $m) {
            if (auth()->check() && auth()->user()->business_id) $m->business_id ??= auth()->user()->business_id;
        });
    }
    protected $casts = [
        'ab_variants'=>'array','performance_data'=>'array','created_by_agent'=>'boolean',
        'schedule_at'=>'datetime','sent_at'=>'datetime'
    ];
    public function getOpenRateAttribute(): float {
        return $this->send_count > 0 ? round($this->open_count / $this->send_count * 100, 2) : 0;
    }
    public function getClickRateAttribute(): float {
        return $this->send_count > 0 ? round($this->click_count / $this->send_count * 100, 2) : 0;
    }
    public function candidates() { return $this->hasMany(Candidate::class); }
    public function agentJobs() { return $this->hasMany(AgentJob::class); }
}
