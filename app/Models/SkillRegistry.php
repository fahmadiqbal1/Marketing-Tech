<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class SkillRegistry extends Model {
    protected $table = 'skills_registry';
    use HasUuids;
    protected $fillable = [
        'name','class','category','description','input_schema','output_schema',
        'required_permissions','required_services','queue','timeout_seconds',
        'max_retries','is_active','is_async','usage_count','avg_duration_ms'
    ];
    protected $casts = [
        'input_schema'=>'array','output_schema'=>'array','required_permissions'=>'array',
        'required_services'=>'array','is_active'=>'boolean','is_async'=>'boolean',
        'avg_duration_ms'=>'float'
    ];
    public function incrementUsage(float $durationMs): void {
        $newAvg = $this->avg_duration_ms
            ? ($this->avg_duration_ms * $this->usage_count + $durationMs) / ($this->usage_count + 1)
            : $durationMs;
        $this->update(['usage_count'=>$this->usage_count+1,'avg_duration_ms'=>$newAvg]);
    }
}
