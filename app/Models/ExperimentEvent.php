<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ExperimentEvent extends Model {
    public $timestamps = false;
    protected $fillable = ['experiment_id','variant','event_type','value','metadata','occurred_at'];
    protected $casts = ['metadata'=>'array','value'=>'float','occurred_at'=>'datetime'];
    public function experiment() { return $this->belongsTo(Experiment::class); }
}
