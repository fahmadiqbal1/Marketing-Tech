<?php

namespace App\Http\Controllers;

use App\Jobs\RunAgentJob;
use App\Models\AgentJob;
use App\Models\AgentStep;
use App\Models\ContentVariation;
use App\Models\GeneratedOutput;
use App\Services\CampaignContextService;
use App\Services\IterationEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PipelineActionController — actionable endpoints for pipeline management.
 *
 * Routes (routes/web.php under dashboard/api/pipeline):
 *   POST  /dashboard/api/pipeline/steps/{id}/skip      → skipStep()
 *   POST  /dashboard/api/pipeline/jobs/{id}/retry      → retryJob()
 *   GET   /dashboard/api/variations/{jobId}            → listVariations()
 *   POST  /dashboard/api/variations/{id}/performance   → recordPerformance()
 *   GET   /dashboard/api/campaigns/{id}/intelligence   → campaignIntelligence()
 */
class PipelineActionController extends Controller
{
    public function __construct(
        private readonly IterationEngineService $iterationEngine,
        private readonly CampaignContextService $campaignContext,
    ) {}

    // ── Step Actions ──────────────────────────────────────────────────

    public function skipStep(string $id): JsonResponse
    {
        $step = AgentStep::findOrFail($id);

        if ($step->status === 'completed') {
            return response()->json(['error' => 'Step is already completed.'], 422);
        }

        $step->update(['status' => 'skipped']);

        return response()->json([
            'message'     => 'Step marked as skipped.',
            'step_id'     => $step->id,
            'step_number' => $step->step_number,
        ]);
    }

    // ── Job Actions ───────────────────────────────────────────────────

    public function retryJob(string $id): JsonResponse
    {
        $job = AgentJob::findOrFail($id);

        if ($job->status === 'running') {
            return response()->json(['error' => 'Job is currently running.'], 422);
        }

        $queue = config("agents.agents.{$job->agent_type}.queue", 'default');

        $job->update([
            'status'        => 'pending',
            'error_message' => null,
            'result'        => null,
            'steps_taken'   => 0,
            'started_at'    => null,
            'completed_at'  => null,
        ]);

        RunAgentJob::dispatch($job->id)->onQueue($queue);

        return response()->json([
            'message' => 'Job re-queued for retry.',
            'job_id'  => $job->id,
            'status'  => 'pending',
        ]);
    }

    // ── Variation Endpoints ───────────────────────────────────────────

    public function listVariations(string $jobId): JsonResponse
    {
        $job = AgentJob::findOrFail($jobId);

        $variations = $job->contentVariations()
            ->with('performance')
            ->orderBy('variation_label')
            ->get()
            ->map(fn(ContentVariation $v) => [
                'id'              => $v->id,
                'variation_label' => $v->variation_label,
                'content'         => $v->content,
                'metadata'        => $v->metadata,
                'is_winner'       => $v->is_winner,
                'created_at'      => $v->created_at?->toIso8601String(),
                'latest_score'    => $v->latestScore(),
                'performance'     => $v->performance->map(fn($p) => [
                    'impressions'  => $p->impressions,
                    'clicks'       => $p->clicks,
                    'conversions'  => $p->conversions,
                    'ctr'          => $p->ctr,
                    'score'        => $p->score,
                    'source'       => $p->source,
                    'recorded_at'  => $p->recorded_at?->toIso8601String(),
                ]),
            ]);

        return response()->json([
            'job_id'     => $jobId,
            'variations' => $variations,
        ]);
    }

    public function recordPerformance(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'impressions' => 'required|integer|min:0',
            'clicks'      => 'required|integer|min:0',
            'conversions' => 'required|integer|min:0',
            'source'      => 'nullable|string|in:manual,webhook,simulated',
        ]);

        $variation = ContentVariation::findOrFail($id);

        $perf = $this->iterationEngine->recordPerformance(
            variationId:  $id,
            impressions:  $validated['impressions'],
            clicks:       $validated['clicks'],
            conversions:  $validated['conversions'],
            source:       $validated['source'] ?? 'manual',
        );

        return response()->json([
            'message'      => 'Performance recorded.',
            'variation_id' => $id,
            'score'        => $perf->score,
            'is_winner'    => $variation->fresh()?->is_winner,
        ], 201);
    }

    // ── Campaign Intelligence ─────────────────────────────────────────

    public function campaignIntelligence(string $id): JsonResponse
    {
        return response()->json([
            'campaign_id'  => $id,
            'context'      => $this->campaignContext->getCampaignContext($id),
            'history'      => $this->campaignContext->getCampaignHistory($id),
            'best_assets'  => $this->campaignContext->getBestPerformingAssets($id),
        ]);
    }

    // ── Winner Promotion ──────────────────────────────────────────────

    /**
     * POST /dashboard/api/pipeline/jobs/{id}/promote-winner
     *
     * Manually promote the best-scoring variation as winner for a job,
     * bypassing the statistical minimum threshold (explicit override).
     */
    public function promoteWinner(string $id): JsonResponse
    {
        $job = AgentJob::findOrFail($id);

        $winner = $this->iterationEngine->selectWinnerForJob($job->id, forceSelect: true);

        if ($winner === null) {
            return response()->json([
                'message' => 'No variations found for this job.',
                'job_id'  => $id,
            ], 422);
        }

        return response()->json([
            'message'          => 'Winner promoted.',
            'job_id'           => $id,
            'winner_id'        => $winner->id,
            'variation_label'  => $winner->variation_label,
            'content_preview'  => Str::limit($winner->content, 200),
        ]);
    }

    /**
     * POST /dashboard/api/pipeline/jobs/{id}/rerun-from-winner
     *
     * Create a new AgentJob seeded with the winning content from the given job,
     * so the next run builds on the best-performing output.
     */
    public function rerunFromWinner(string $id): JsonResponse
    {
        $job = AgentJob::findOrFail($id);

        // Find winner — prefer ContentVariation winner, fall back to winner GeneratedOutput
        $winnerContent = null;

        $winnerVariation = ContentVariation::where('agent_job_id', $job->id)
            ->where('is_winner', true)
            ->orderByDesc('created_at')
            ->first();

        if ($winnerVariation) {
            $winnerContent = $winnerVariation->content;
        } else {
            $winnerOutput = GeneratedOutput::where('agent_job_id', $job->id)
                ->where('is_winner', true)
                ->orderByDesc('created_at')
                ->first();
            if ($winnerOutput) {
                $winnerContent = $winnerOutput->content;
            }
        }

        if ($winnerContent === null) {
            return response()->json([
                'message' => 'No winner found for this job. Run promote-winner first.',
                'job_id'  => $id,
            ], 422);
        }

        $excerpt     = Str::limit($winnerContent, 500);
        $instruction = $job->instruction
            . "\n\nBase your response on this winning content:\n"
            . $excerpt;

        $newJob = AgentJob::create([
            'agent_type'  => $job->agent_type,
            'ai_provider' => $job->ai_provider,
            'model'       => $job->model,
            'campaign_id' => $job->campaign_id,
            'chat_id'     => $job->chat_id,
            'instruction' => $instruction,
            'status'      => 'pending',
        ]);

        $queue = config("agents.agents.{$newJob->agent_type}.queue", 'default');
        RunAgentJob::dispatch($newJob->id)->onQueue($queue);

        return response()->json([
            'message'    => 'New job created from winner.',
            'new_job_id' => $newJob->id,
            'source_job' => $id,
            'status'     => 'pending',
        ], 201);
    }
}
