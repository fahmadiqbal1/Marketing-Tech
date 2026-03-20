<?php

namespace App\Services\Dashboard;

use App\Models\AgentJob;
use App\Models\AiRequest;
use App\Models\Campaign;
use App\Models\Candidate;
use App\Models\ContentItem;
use App\Models\SystemEvent;
use App\Models\Workflow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Encapsulates all dashboard query logic.
 * DashboardController delegates to this service and stays thin.
 */
class DashboardStatsService
{
    // ── Overview ─────────────────────────────────────────────────────

    public function getStats(): array
    {
        $workflowCounts = Workflow::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $activeJobs = AgentJob::whereIn('status', ['pending', 'running'])->count();

        $aiCostToday = AiRequest::whereDate('requested_at', today())->sum('cost_usd');
        $aiCostWeek  = AiRequest::where('requested_at', '>=', now()->subDays(7))->sum('cost_usd');

        $recentWorkflows = Workflow::latest()
            ->limit(8)
            ->get(['id', 'name', 'type', 'status', 'created_at', 'completed_at', 'error_message']);

        $recentEvents = SystemEvent::latest()
            ->limit(5)
            ->get(['id', 'level', 'event', 'message', 'created_at']);

        $queueDepth = DB::table('jobs')->count();

        return [
            'workflows'        => $workflowCounts,
            'active_jobs'      => $activeJobs,
            'queue_depth'      => $queueDepth,
            'ai_cost_today'    => round((float) $aiCostToday, 4),
            'ai_cost_week'     => round((float) $aiCostWeek, 4),
            'recent_workflows' => $recentWorkflows,
            'recent_events'    => $recentEvents,
        ];
    }

    // ── Workflows ────────────────────────────────────────────────────

    public function getWorkflows(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Workflow::latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['search'])) {
            $query->where('name', 'ilike', '%' . $filters['search'] . '%');
        }

        return $query->paginate($perPage, [
            'id', 'name', 'type', 'status', 'requires_approval',
            'approval_granted', 'retry_count', 'error_message',
            'created_at', 'started_at', 'completed_at',
        ]);
    }

    public function getWorkflowDetail(string $id): array
    {
        $workflow = Workflow::findOrFail($id);

        return [
            'workflow' => $workflow,
            'logs'     => $workflow->logs()->latest('logged_at')->limit(50)->get(),
            'tasks'    => $workflow->tasks()->get(),
        ];
    }

    // ── Jobs ─────────────────────────────────────────────────────────

    public function getJobs(): array
    {
        $jobs = AgentJob::latest()
            ->limit(50)
            ->get(['id', 'agent_type', 'status', 'queue', 'attempts',
                   'progress', 'started_at', 'completed_at', 'error_message', 'created_at']);

        $byQueue = $jobs->groupBy('queue')->map(fn ($g) => [
            'total'   => $g->count(),
            'running' => $g->where('status', 'running')->count(),
            'pending' => $g->where('status', 'pending')->count(),
            'failed'  => $g->where('status', 'failed')->count(),
        ]);

        $queueTable = DB::table('jobs')
            ->selectRaw('queue, count(*) as pending')
            ->groupBy('queue')
            ->pluck('pending', 'queue');

        return [
            'jobs'        => $jobs,
            'by_queue'    => $byQueue,
            'queue_table' => $queueTable,
        ];
    }

    // ── Campaigns ────────────────────────────────────────────────────

    public function getCampaigns(): array
    {
        return ['data' => Campaign::latest()->get()];
    }

    // ── Candidates ───────────────────────────────────────────────────

    public function getCandidates(): array
    {
        $candidates = Candidate::latest()
            ->get(['id', 'name', 'email', 'pipeline_stage', 'score',
                   'current_title', 'current_company', 'created_at']);

        return [
            'data'     => $candidates,
            'by_stage' => $candidates->groupBy('pipeline_stage'),
        ];
    }

    // ── Content ──────────────────────────────────────────────────────

    public function getContent(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = ContentItem::latest();

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage, [
            'id', 'title', 'type', 'platform', 'status',
            'word_count', 'scheduled_at', 'published_at', 'created_at',
        ]);
    }

    // ── System Events ────────────────────────────────────────────────

    public function getSystemEvents(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = SystemEvent::latest();

        if (! empty($filters['level'])) {
            $query->where('level', $filters['level']);
        }

        return $query->paginate($perPage, [
            'id', 'level', 'event', 'message', 'context', 'created_at',
        ]);
    }

    // ── AI Costs ─────────────────────────────────────────────────────

    public function getAiCosts(int $days = 7): array
    {
        $since = now()->subDays($days);

        $breakdown = AiRequest::selectRaw(
            "provider, model, COUNT(*) as requests,
             SUM(tokens_in) as tokens_in, SUM(tokens_out) as tokens_out,
             ROUND(SUM(cost_usd)::numeric, 4) as total_cost"
        )
        ->where('requested_at', '>=', $since)
        ->groupBy('provider', 'model')
        ->orderByDesc('total_cost')
        ->get();

        $daily = AiRequest::selectRaw(
            "DATE(requested_at) as date, ROUND(SUM(cost_usd)::numeric, 4) as cost"
        )
        ->where('requested_at', '>=', $since)
        ->groupBy('date')
        ->orderBy('date')
        ->get();

        return [
            'period_days' => $days,
            'total'       => round((float) $breakdown->sum('total_cost'), 4),
            'breakdown'   => $breakdown,
            'daily'       => $daily,
            'by_task'     => $this->getTaskCosts(),
        ];
    }

    /**
     * Per-agent-task cost breakdown — enables debugging expensive runs
     * and is the foundation for future per-task billing.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getTaskCosts(int $limit = 20): \Illuminate\Support\Collection
    {
        return AiRequest::whereNotNull('agent_task_id')
            ->selectRaw(
                'agent_task_id, COUNT(*) as requests,
                 SUM(tokens_in) as tokens_in, SUM(tokens_out) as tokens_out,
                 ROUND(SUM(cost_usd)::numeric, 6) as total_cost'
            )
            ->groupBy('agent_task_id')
            ->orderByDesc('total_cost')
            ->limit($limit)
            ->get();
    }
}
