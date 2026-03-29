<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Candidate extends Model
{
    use HasUuids, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new BusinessScope());
        static::creating(function (self $model) {
            if (auth()->check() && auth()->user()->business_id) {
                $model->business_id ??= auth()->user()->business_id;
            }
        });
    }

    protected $fillable = [
        'business_id', 'applied_job_id', 'name', 'email', 'phone', 'location', 'linkedin_url', 'github_url',
        'summary', 'years_experience', 'current_title', 'current_company', 'skills', 'education',
        'experience', 'certifications', 'languages', 'source', 'pipeline_stage', 'pipeline_notes',
        'stage_updated_at', 'score', 'score_details', 'scored_for_job', 'cv_raw', 'outreach_history', 'embedding',
    ];

    protected $casts = [
        'skills' => 'array', 'education' => 'array', 'experience' => 'array',
        'certifications' => 'array', 'languages' => 'array', 'score_details' => 'array',
        'outreach_history' => 'array', 'stage_updated_at' => 'datetime',
    ];

    public function getSkillsTextAttribute(): string
    {
        return implode(', ', $this->skills ?? []);
    }

    public function getExperienceTextAttribute(): string
    {
        return collect($this->experience ?? [])->map(fn ($e) => "{$e['title']} at {$e['company']}: {$e['summary']}")->implode('. ');
    }

    public function jobPosting()
    {
        return $this->belongsTo(JobPosting::class, 'applied_job_id');
    }

    public static function search(array $embedding, ?string $jobId = null, ?string $stage = null, float $minScore = 0, int $limit = 10)
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return collect();
        }

        $safeFloats = array_map('floatval', $embedding);
        $vec = '['.implode(',', $safeFloats).']';
        $query = DB::table('candidates')
            ->selectRaw('*, 1 - (embedding <=> ?::vector) as similarity', [$vec])
            ->whereNull('deleted_at')
            ->whereRaw('1 - (embedding <=> ?::vector) >= 0.5', [$vec])
            ->orderByRaw('embedding <=> ?::vector', [$vec])
            ->limit($limit);
        if ($jobId) {
            $query->where('applied_job_id', $jobId);
        }
        if ($stage) {
            $query->where('pipeline_stage', $stage);
        }
        if ($minScore > 0) {
            $query->where('score', '>=', $minScore);
        }

        return $query->get();
    }
}
