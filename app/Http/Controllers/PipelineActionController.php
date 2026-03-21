<?php

namespace App\Http\Controllers;

use App\Jobs\RunAgentJob;
use App\Models\AgentJob;
use App\Models\AgentStep;
use App\Models\ContentVariation;
use App\Services\CampaignContextService;
use App\Services\IterationEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
