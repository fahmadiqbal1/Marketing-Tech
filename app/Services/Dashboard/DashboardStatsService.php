<?php

namespace App\Services\Dashboard;

use App\Models\AgentJob;
use App\Models\AiRequest;
use App\Models\Campaign;
use App\Models\Candidate;
use App\Models\ContentItem;
use App\Models\SystemEvent;
use App\Models\Workflow;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Encapsulates all dashboard query logic.
 * DashboardController delegates to this service and stays thin.
 */
class DashboardStatsService
{
    private ?bool $databaseAvailable = null;

    private ?string $databaseError = null;

    public function getStats(): array
    {
        return $this->rescueArray(function (): array {
            $workflowCounts = Workflow::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $activeJobs = AgentJob::query()->whereIn('status', ['pending', 'running'])->count();

            $aiCostToday     = AiRequest::query()->whereDate('requested_at', today())->sum('cost_usd');
            $aiCostYesterday = AiRequest::query()->whereDate('requested_at', today()->subDay())->sum('cost_usd');
            $aiCostWeek      = AiRequest::query()->where('requested_at', '>=', now()->subDays(7))->sum('cost_usd');

            $recentWorkflows = Workflow::query()
                ->latest()
                ->limit(8)
                ->get(['id', 'name', 'type', 'status', 'created_at', 'completed_at', 'error_message']);

            $recentEvents = SystemEvent::query()
                ->latest('occurred_at')
                ->limit(5)
                ->get([
                    'id',
                    'severity as level',
                    'event_type as event',
                    'message',
                    'source',
                    'occurred_at as created_at',
                ]);

            $queueDepth = DB::table('jobs')->count();

            $candidateStages = Candidate::query()
                ->selectRaw('pipeline_stage, count(*) as cnt')
                ->whereNotNull('pipeline_stage')
                ->groupBy('pipeline_stage')
                ->pluck('cnt', 'pipeline_stage');

            $failedJobs    = AgentJob::where('status', 'failed')->count();
            $needsApproval = (int) ($workflowCounts->get('owner_approval', 0));

            return [
                'workflows'         => $workflowCounts,
                'active_jobs'       => $activeJobs,
                'queue_depth'       => $queueDepth,
                'ai_cost_today'     => round((float) $aiCostToday, 4),
                'ai_cost_yesterday' => round((float) $aiCostYesterday, 4),
                'ai_cost_week'      => round((float) $aiCostWeek, 4),
                'recent_workflows'  => $recentWorkflows,
                'recent_events'     => $recentEvents,
                'candidate_stages'  => $candidateStages,
                'failed_jobs'       => $failedJobs,
                'needs_approval'    => $needsApproval,
                'meta'              => $this->meta(),
            ];
        }, [
            'workflows'         => [],
            'active_jobs'       => 0,
            'queue_depth'       => 0,
            'ai_cost_today'     => 0.0,
            'ai_cost_yesterday' => 0.0,
            'ai_cost_week'      => 0.0,
            'recent_workflows'  => [],
            'recent_events'     => [],
            'candidate_stages'  => [],
            'failed_jobs'       => 0,
            'needs_approval'    => 0,
        ]);
    }

    public function getWorkflows(array $filters = [], int $perPage = 15): array
    {
        return $this->rescuePaginated(function () use ($filters, $perPage): LengthAwarePaginator {
            $query = Workflow::query()->latest();

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }
            if (! empty($filters['search'])) {
                $query->where('name', 'like', '%' . $filters['search'] . '%');
            }

            return $query->paginate($perPage, [
                'id', 'name', 'type', 'status', 'requires_approval',
                'approval_granted', 'retry_count', 'error_message',
                'created_at', 'started_at', 'completed_at',
            ]);
        }, $perPage);
    }

    public function getWorkflowDetail(string $id): array
    {
        return $this->rescueArray(function () use ($id): array {
            $workflow = Workflow::query()->findOrFail($id);

            return [
                'workflow' => $workflow,
                'logs' => $workflow->logs()->latest('logged_at')->limit(50)->get(),
                'tasks' => $workflow->tasks()->get(),
                'meta' => $this->meta(),
            ];
        }, [
            'workflow' => null,
            'logs' => [],
            'tasks' => [],
        ]);
    }

    public function getJobs(array $filters = [], int $perPage = 25): array
    {
        return $this->rescueArray(function () use ($filters, $perPage): array {
            $query = AgentJob::query()->latest();

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['agent_type'])) {
                $query->where('agent_type', $filters['agent_type']);
            }

            $paginator = $query->paginate($perPage, [
                'id', 'workflow_id', 'agent_type', 'short_description', 'status', 'steps_taken',
                'started_at', 'completed_at', 'error_message', 'created_at',
            ]);

            // Global counts (unfiltered) for summary cards and filter dropdowns
            $byStatus = AgentJob::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $byAgentType = AgentJob::query()
                ->selectRaw('agent_type, count(*) as total')
                ->groupBy('agent_type')
                ->orderByDesc('total')
                ->pluck('total', 'agent_type');

            $queueTable = DB::table('jobs')
                ->selectRaw('queue, count(*) as pending')
                ->groupBy('queue')
                ->pluck('pending', 'queue');

            return $this->paginatedResponse($paginator, [
                'by_status'     => $byStatus,
                'by_agent_type' => $byAgentType,
                'queue_table'   => $queueTable,
                'meta'          => $this->meta(),
            ]);
        }, [
            'data'          => [],
            'current_page'  => 1,
            'last_page'     => 1,
            'total'         => 0,
            'by_status'     => [],
            'by_agent_type' => [],
            'queue_table'   => [],
        ]);
    }

    public function getCampaigns(): array
    {
        return $this->rescueArray(function (): array {
            $campaigns = Campaign::query()
                ->latest()
                ->limit(50)
                ->get([
                    'id', 'name', 'type', 'status', 'audience', 'send_count', 'open_count',
                    'click_count', 'conversion_count', 'revenue_attributed', 'schedule_at', 'sent_at', 'created_at',
                ]);

            return [
                'data' => $campaigns,
                'summary' => [
                    'active' => $campaigns->where('status', 'active')->count(),
                    'draft' => $campaigns->where('status', 'draft')->count(),
                    'sent' => $campaigns->where('status', 'sent')->count(),
                ],
                'meta' => $this->meta(),
            ];
        }, ['data' => [], 'summary' => []]);
    }

    public function getCandidates(array $filters = [], int $perPage = 25): array
    {
        return $this->rescueArray(function () use ($filters, $perPage): array {
            $query = Candidate::query()->latest();

            if (! empty($filters['search'])) {
                $term = $filters['search'];
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'ilike', '%' . $term . '%')
                      ->orWhere('email', 'ilike', '%' . $term . '%');
                });
            }
            if (! empty($filters['pipeline_stage'])) {
                $query->where('pipeline_stage', $filters['pipeline_stage']);
            }
            if (! empty($filters['min_score'])) {
                $query->where('score', '>=', (float) $filters['min_score']);
            }

            $paginator = $query->paginate($perPage, [
                'id', 'name', 'email', 'pipeline_stage', 'score',
                'current_title', 'current_company', 'linkedin_url', 'github_url', 'created_at',
            ]);

            // Global stage counts (unfiltered)
            $byStage = Candidate::query()
                ->selectRaw('pipeline_stage, count(*) as total')
                ->groupBy('pipeline_stage')
                ->pluck('total', 'pipeline_stage');

            return $this->paginatedResponse($paginator, [
                'by_stage' => $byStage,
                'meta'     => $this->meta(),
            ]);
        }, [
            'data'         => [],
            'current_page' => 1,
            'last_page'    => 1,
            'total'        => 0,
            'by_stage'     => [],
        ]);
    }

    public function getContent(array $filters = [], int $perPage = 20): array
    {
        return $this->rescuePaginated(function () use ($filters, $perPage): LengthAwarePaginator {
            $query = ContentItem::query()->latest();

            if (! empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }
            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['search'])) {
                $query->where('title', 'ilike', '%' . $filters['search'] . '%');
            }

            return $query->paginate($perPage, [
                'id', 'title', 'type', 'platform', 'status',
                'word_count', 'scheduled_at', 'published_at', 'created_at',
            ]);
        }, $perPage);
    }

    public function getSystemEvents(array $filters = [], int $perPage = 25): array
    {
        return $this->rescuePaginated(function () use ($filters, $perPage): LengthAwarePaginator {
            $query = SystemEvent::query()->latest('occurred_at');

            if (! empty($filters['level'])) {
                $query->where('severity', $filters['level']);
            }

            return $query->paginate($perPage, [
                'id',
                'severity as level',
                'event_type as event',
                'message',
                'source',
                'payload as context',
                'occurred_at as created_at',
            ]);
        }, $perPage);
    }

    public function getAiCosts(int $days = 7): array
    {
        return $this->rescueArray(function () use ($days): array {
            $since = now()->subDays($days);

            $breakdown = AiRequest::query()
                ->selectRaw('provider, model, COUNT(*) as requests, SUM(tokens_in) as tokens_in, SUM(tokens_out) as tokens_out, SUM(cost_usd) as total_cost')
                ->where('requested_at', '>=', $since)
                ->groupBy('provider', 'model')
                ->orderByDesc('total_cost')
                ->get()
                ->map(function (AiRequest $row) {
                    $row->total_cost = round((float) $row->total_cost, 4);
                    return $row;
                });

            $daily = AiRequest::query()
                ->selectRaw('DATE(requested_at) as date, SUM(cost_usd) as cost')
                ->where('requested_at', '>=', $since)
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(function (AiRequest $row) {
                    return [
                        'date' => $row->date,
                        'cost' => round((float) $row->cost, 4),
                    ];
                });

            return [
                'period_days' => $days,
                'total' => round((float) $breakdown->sum('total_cost'), 4),
                'breakdown' => $breakdown,
                'daily' => $daily,
                'by_task' => $this->getTaskCosts(),
                'meta' => $this->meta(),
            ];
        }, [
            'period_days' => $days,
            'total' => 0.0,
            'breakdown' => [],
            'daily' => [],
            'by_task' => [],
        ]);
    }

    public function getTaskCosts(int $limit = 20): array
    {
        return $this->rescueArray(function () use ($limit): array {
            return AiRequest::query()
                ->whereNotNull('agent_task_id')
                ->selectRaw('agent_task_id, COUNT(*) as requests, SUM(tokens_in) as tokens_in, SUM(tokens_out) as tokens_out, SUM(cost_usd) as total_cost')
                ->groupBy('agent_task_id')
                ->orderByDesc('total_cost')
                ->limit($limit)
                ->get()
                ->map(function (AiRequest $row) {
                    $row->total_cost = round((float) $row->total_cost, 6);
                    return $row;
                })
                ->all();
        }, []);
    }

    private function rescueArray(callable $callback, array $fallback): array
    {
        try {
            if (! $this->databaseReachable()) {
                return $this->fallback($fallback);
            }

            return $callback();
        } catch (\Throwable $e) {
            $this->reportDatabaseException($e);

            return $this->fallback($fallback);
        }
    }

    private function rescuePaginated(callable $callback, int $perPage): array
    {
        try {
            if (! $this->databaseReachable()) {
                return $this->emptyPagination($perPage);
            }

            return $this->paginationPayload($callback());
        } catch (\Throwable $e) {
            $this->reportDatabaseException($e);

            return $this->emptyPagination($perPage);
        }
    }

    private function databaseReachable(): bool
    {
        if ($this->databaseAvailable !== null) {
            return $this->databaseAvailable;
        }

        try {
            DB::connection()->getPdo();
            $this->databaseAvailable = true;
            $this->databaseError = null;
        } catch (\Throwable $e) {
            $this->databaseAvailable = false;
            $this->databaseError = $this->normalizeDatabaseError($e);
            Log::warning('Dashboard database unavailable', ['error' => $e->getMessage()]);
        }

        return $this->databaseAvailable;
    }

    /**
     * Canonical pagination shape used by all dashboard list endpoints.
     * Strips Laravel noise (links, path, from, to) so only the 5 keys
     * the frontend actually reads are present.
     */
    private function paginatedResponse(LengthAwarePaginator $p, array $extras = []): array
    {
        return array_merge([
            'data'         => $p->items(),
            'current_page' => $p->currentPage(),
            'last_page'    => $p->lastPage(),
            'total'        => $p->total(),
            'per_page'     => $p->perPage(),
        ], $extras);
    }

    private function paginationPayload(LengthAwarePaginator $paginator): array
    {
        return $this->paginatedResponse($paginator, ['meta' => $this->meta()]);
    }

    private function emptyPagination(int $perPage): array
    {
        return array_merge((new LengthAwarePaginator([], 0, $perPage))->toArray(), ['meta' => $this->meta(false)]);
    }

    private function fallback(array $fallback): array
    {
        return array_merge($fallback, ['meta' => $this->meta(false)]);
    }

    private function meta(?bool $available = null): array
    {
        $available = $available ?? $this->databaseAvailable ?? false;

        return [
            'database_available' => $available,
            'warning' => $available ? null : ($this->databaseError ?? 'Database is not available in the current environment.'),
        ];
    }

    private function reportDatabaseException(\Throwable $e): void
    {
        $this->databaseAvailable = false;
        $this->databaseError = $this->normalizeDatabaseError($e);

        Log::warning('Dashboard query failed; returning fallback payload.', [
            'error' => $e->getMessage(),
            'class' => $e::class,
        ]);
    }

    private function normalizeDatabaseError(\Throwable $e): string
    {
        $message = trim($e->getMessage());

        if (str_contains($message, 'could not find driver')) {
            return 'The configured database driver is not installed in this PHP environment.';
        }

        if (str_contains($message, 'Connection refused')) {
            return 'The application cannot reach the configured database server.';
        }

        if (str_contains($message, 'no such table') || str_contains($message, 'Undefined table')) {
            return 'The database connection works, but the schema has not been migrated yet.';
        }

        return 'Dashboard data is temporarily unavailable because the database is not ready.';
    }
}
