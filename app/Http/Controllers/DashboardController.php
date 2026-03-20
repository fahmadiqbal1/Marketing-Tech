<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardStatsService;
use App\Workflows\WorkflowDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DashboardController – thin controller.
 * All query logic lives in DashboardStatsService.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardStatsService $stats) {}

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
        return response()->json($this->stats->getStats());
    }

    // ── API: Workflows ────────────────────────────────────────────────

    public function apiWorkflows(Request $request): JsonResponse
    {
        $workflows = $this->stats->getWorkflows($request->only(['status', 'type', 'search']));
        return response()->json($workflows);
    }

    public function apiWorkflowDetail(string $id): JsonResponse
    {
        return response()->json($this->stats->getWorkflowDetail($id));
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

    public function apiJobs(): JsonResponse
    {
        return response()->json($this->stats->getJobs());
    }

    // ── API: Campaigns ────────────────────────────────────────────────

    public function apiCampaigns(): JsonResponse
    {
        return response()->json($this->stats->getCampaigns());
    }

    // ── API: Candidates ───────────────────────────────────────────────

    public function apiCandidates(): JsonResponse
    {
        return response()->json($this->stats->getCandidates());
    }

    // ── API: Content ──────────────────────────────────────────────────

    public function apiContent(Request $request): JsonResponse
    {
        $items = $this->stats->getContent($request->only(['type', 'status']));
        return response()->json($items);
    }

    // ── API: System Events ────────────────────────────────────────────

    public function apiSystemEvents(Request $request): JsonResponse
    {
        $events = $this->stats->getSystemEvents($request->only(['level']));
        return response()->json($events);
    }

    // ── API: AI Costs ─────────────────────────────────────────────────

    public function apiAiCosts(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 7);
        return response()->json($this->stats->getAiCosts($days));
    }
}
