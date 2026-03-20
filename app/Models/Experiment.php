<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Experiment extends Model {
    use HasUuids;
    protected $fillable = [
        'name','type','category','status','hypothesis','metric_primary','metrics_secondary',
        'variants','winning_variant','confidence_level','achieved_significance',
        'min_sample_size','current_sample_size','results','conclusion','learnings',
        'auto_generated','parent_campaign_id','started_at','concluded_at'
    ];
    protected $casts = [
        'metrics_secondary'=>'array','variants'=>'array','results'=>'array','learnings'=>'array',
        'auto_generated'=>'boolean','confidence_level'=>'float','achieved_significance'=>'float',
        'started_at'=>'datetime','concluded_at'=>'datetime'
    ];
    public function events() { return $this->hasMany(ExperimentEvent::class); }
    public function isSignificant(): bool { return $this->achieved_significance !== null && $this->achieved_significance < (1 - $this->confidence_level/100); }
}
