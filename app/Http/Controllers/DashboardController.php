<?php

namespace App\Http\Controllers;

use App\Models\AgentJob;
use App\Models\AiRequest;
use App\Models\Campaign;
use App\Models\Candidate;
use App\Models\ContentItem;
use App\Models\SystemEvent;
use App\Models\Workflow;
use App\Models\WorkflowLog;
use App\Workflows\WorkflowDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // ── Page renders ──────────────────────────────────────────────────

    public function overview()   { return view('overview'); }
    public function workflows()  { return view('workflows'); }
    public function jobs()       { return view('jobs'); }
    public function campaigns()  { return view('campaigns'); }
    public function candidates() { return view('candidates'); }
    public function content()    { return view('content'); }
    public function system()     { return view('system'); }

    // ── API: Overview stats ───────────────────────────────────────────

    public function apiStats(): JsonResponse
    {
        $workflowCounts = Workflow::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $activeJobs = AgentJob::whereIn('status', ['pending', 'running'])->count();

        $aiCostToday = AiRequest::whereDate('requested_at', today())
            ->sum('cost_usd');

        $aiCostWeek = AiRequest::where('requested_at', '>=', now()->subDays(7))
            ->sum('cost_usd');

        $recentWorkflows = Workflow::latest()
            ->limit(8)
            ->get(['id', 'name', 'type', 'status', 'created_at', 'completed_at', 'error_message']);

        $recentEvents = SystemEvent::latest()
            ->limit(5)
            ->get(['id', 'level', 'event', 'message', 'created_at']);

        $queueDepth = DB::table('jobs')->count();

        return response()->json([
            'workflows'        => $workflowCounts,
            'active_jobs'      => $activeJobs,
            'queue_depth'      => $queueDepth,
            'ai_cost_today'    => round((float) $aiCostToday, 4),
            'ai_cost_week'     => round((float) $aiCostWeek, 4),
            'recent_workflows' => $recentWorkflows,
            'recent_events'    => $recentEvents,
        ]);
    }

    // ── API: Workflows ────────────────────────────────────────────────

    public function apiWorkflows(Request $request): JsonResponse
    {
        $query = Workflow::latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($search = $request->query('search')) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        $workflows = $query->paginate(15, [
            'id', 'name', 'type', 'status', 'requires_approval',
            'approval_granted', 'retry_count', 'error_message',
            'created_at', 'started_at', 'completed_at',
        ]);

        return response()->json($workflows);
    }

    public function apiWorkflowDetail(string $id): JsonResponse
    {
        $workflow = Workflow::findOrFail($id);
        $logs     = $workflow->logs()->latest('logged_at')->limit(50)->get();
        $tasks    = $workflow->tasks()->get();

        return response()->json([
            'workflow' => $workflow,
            'logs'     => $logs,
            'tasks'    => $tasks,
        ]);
    }

    public function apiWorkflowApprove(string $id): JsonResponse
    {
        $approved = app(WorkflowDispatcher::class)->approve($id, 'dashboard');
        return response()->json(['approved' => $approved]);
    }

    public function apiWorkflowCancel(string $id): JsonResponse
    {
        $cancelled = app(WorkflowDispatcher::class)->cancel($id);
        return response()->json(['cancelled' => $cancelled]);
    }

    public function apiWorkflowRetry(string $id): JsonResponse
    {
        $retried = app(WorkflowDispatcher::class)->retry($id);
        return response()->json(['retried' => $retried]);
    }

    // ── API: Agent Jobs ───────────────────────────────────────────────

    public function apiJobs(Request $request): JsonResponse
    {
        $jobs = AgentJob::latest()
            ->limit(50)
            ->get(['id', 'agent_type', 'status', 'queue', 'attempts',
                   'progress', 'started_at', 'completed_at', 'error_message', 'created_at']);

        $byQueue = $jobs->groupBy('queue')->map(fn($g) => [
            'total'   => $g->count(),
            'running' => $g->where('status', 'running')->count(),
            'pending' => $g->where('status', 'pending')->count(),
            'failed'  => $g->where('status', 'failed')->count(),
        ]);

        $queueTable = DB::table('jobs')
            ->selectRaw('queue, count(*) as pending')
            ->groupBy('queue')
            ->pluck('pending', 'queue');

        return response()->json([
            'jobs'        => $jobs,
            'by_queue'    => $byQueue,
            'queue_table' => $queueTable,
        ]);
    }

    // ── API: Campaigns ────────────────────────────────────────────────

    public function apiCampaigns(): JsonResponse
    {
        $campaigns = Campaign::latest()->get();
        return response()->json(['data' => $campaigns]);
    }

    // ── API: Candidates ───────────────────────────────────────────────

    public function apiCandidates(): JsonResponse
    {
        $candidates = Candidate::latest()
            ->get(['id', 'name', 'email', 'pipeline_stage', 'score',
                   'current_title', 'current_company', 'created_at']);

        $byStage = $candidates->groupBy('pipeline_stage');

        return response()->json([
            'data'     => $candidates,
            'by_stage' => $byStage,
        ]);
    }

    // ── API: Content ──────────────────────────────────────────────────

    public function apiContent(Request $request): JsonResponse
    {
        $query = ContentItem::latest();
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        $items = $query->paginate(20, [
            'id', 'title', 'type', 'platform', 'status',
            'word_count', 'scheduled_at', 'published_at', 'created_at',
        ]);
        return response()->json($items);
    }

    // ── API: System Events ────────────────────────────────────────────

    public function apiSystemEvents(Request $request): JsonResponse
    {
        $query = SystemEvent::latest();
        if ($level = $request->query('level')) {
            $query->where('level', $level);
        }
        $events = $query->paginate(25, [
            'id', 'level', 'event', 'message', 'context', 'created_at',
        ]);
        return response()->json($events);
    }

    // ── API: AI Costs ─────────────────────────────────────────────────

    public function apiAiCosts(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 7);

        $breakdown = AiRequest::selectRaw(
            "provider, model, COUNT(*) as requests,
             SUM(tokens_in) as tokens_in, SUM(tokens_out) as tokens_out,
             ROUND(SUM(cost_usd)::numeric, 4) as total_cost"
        )
        ->where('requested_at', '>=', now()->subDays($days))
        ->groupBy('provider', 'model')
        ->orderByDesc('total_cost')
        ->get();

        $daily = AiRequest::selectRaw(
            "DATE(requested_at) as date, ROUND(SUM(cost_usd)::numeric, 4) as cost"
        )
        ->where('requested_at', '>=', now()->subDays($days))
        ->groupBy('date')
        ->orderBy('date')
        ->get();

        return response()->json([
            'period_days' => $days,
            'total'       => round((float) $breakdown->sum('total_cost'), 4),
            'breakdown'   => $breakdown,
            'daily'       => $daily,
        ]);
    }
}
