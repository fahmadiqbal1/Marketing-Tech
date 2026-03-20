<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
class JobPosting extends Model {
    use HasUuids, SoftDeletes;
    protected $fillable = [
        'title','department','location','employment_type','level','description',
        'requirements','nice_to_have','salary_range','status','target_hires',
        'applicant_count','agent_run_id','metadata'
    ];
    protected $casts = ['requirements'=>'array','nice_to_have'=>'array','metadata'=>'array'];
    public function getRequirementsTextAttribute(): string { return implode(', ', $this->requirements ?? []); }
    public function getNiceToHaveTextAttribute(): string { return implode(', ', $this->nice_to_have ?? []); }
    public function candidates() { return $this->hasMany(Candidate::class, 'applied_job_id'); }
}
