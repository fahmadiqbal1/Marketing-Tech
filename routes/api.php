<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;
use App\Models\Workflow;
use App\Models\Experiment;
use App\Models\Campaign;
use App\Models\Candidate;
use App\Workflows\WorkflowDispatcher;
use App\Services\Growth\ExperimentationEngine;
use App\Services\Knowledge\VectorStoreService;
use App\Services\Supervisor\SupervisorService;

/*
|--------------------------------------------------------------------------
| Telegram Webhook — no auth, verified by HMAC secret header
|--------------------------------------------------------------------------
*/
Route::post('/webhook/telegram', [TelegramController::class, 'webhook'])
    ->name('telegram.webhook')
    ->middleware('throttle:100,1');

/*
|--------------------------------------------------------------------------
| Protected API — requires Sanctum token
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Telegram webhook management
    Route::post('/webhook/telegram/register', [TelegramController::class, 'register']);
    Route::get('/webhook/telegram/info',      [TelegramController::class, 'info']);

    Route::prefix('v1')->group(function () {

        // ── Workflows ────────────────────────────────────────────────
        Route::get('/workflows', fn() => Workflow::latest()->paginate(20));
        Route::get('/workflows/{id}', fn($id) => Workflow::findOrFail($id));
        Route::get('/workflows/{id}/tasks', fn($id) => Workflow::findOrFail($id)->tasks()->get());
        Route::get('/workflows/{id}/logs',  fn($id) => Workflow::findOrFail($id)->logs()->latest('logged_at')->paginate(50));

        Route::post('/workflows/{id}/approve', function ($id) {
            $approved = app(WorkflowDispatcher::class)->approve($id, request()->user()?->email ?? 'api');
            return response()->json(['approved' => $approved]);
        });

        Route::post('/workflows/{id}/cancel', function ($id) {
            $cancelled = app(WorkflowDispatcher::class)->cancel($id);
            return response()->json(['cancelled' => $cancelled]);
        });

        Route::post('/workflows/{id}/retry', function ($id) {
            $retried = app(WorkflowDispatcher::class)->retry($id);
            return response()->json(['retried' => $retried]);
        });

        // ── Experiments ──────────────────────────────────────────────
        Route::get('/experiments', fn() => Experiment::latest()->paginate(20));
        Route::get('/experiments/{id}', fn($id) => Experiment::findOrFail($id));

        Route::get('/experiments/{id}/results', function ($id) {
            $exp     = Experiment::findOrFail($id);
            $results = app(ExperimentationEngine::class)->analyze($exp);
            return response()->json($results);
        });

        Route::post('/experiments/{id}/start', function ($id) {
            $exp = Experiment::findOrFail($id);
            app(ExperimentationEngine::class)->start($exp);
            return response()->json(['status' => $exp->fresh()->status]);
        });

        Route::post('/experiments/{id}/conclude', function ($id) {
            $exp     = Experiment::findOrFail($id);
            $results = app(ExperimentationEngine::class)->analyze($exp);
            return response()->json($results);
        });

        // ── Campaigns ────────────────────────────────────────────────
        Route::get('/campaigns', fn() => Campaign::latest()->paginate(20));
        Route::get('/campaigns/{id}', fn($id) => Campaign::findOrFail($id));
        Route::get('/campaigns/{id}/stats', function ($id) {
            $campaign = Campaign::findOrFail($id);
            return response()->json(
                app(\App\Services\Marketing\CampaignService::class)->getCampaignStats($campaign)
            );
        });

        // ── Candidates ───────────────────────────────────────────────
        Route::get('/candidates', fn() => Candidate::latest()->paginate(20));
        Route::get('/candidates/{id}', fn($id) => Candidate::findOrFail($id));

        Route::get('/candidates/search', function () {
            $q    = request('q', '');
            $jobId = request('job_id');
            if (empty($q)) {
                return response()->json([]);
            }
            $embedding = app(VectorStoreService::class)->search($q, 10);
            return response()->json($embedding);
        });

        // ── Knowledge Base ───────────────────────────────────────────
        Route::get('/knowledge/search', function () {
            $q        = request('q', '');
            $category = request('category');
            if (empty($q)) {
                return response()->json(['results' => []]);
            }
            $results = app(VectorStoreService::class)->search($q, 10, $category);
            return response()->json(['results' => $results, 'query' => $q]);
        });

        Route::post('/knowledge', function () {
            $data = request()->validate([
                'title'    => 'required|string|max:500',
                'content'  => 'required|string',
                'category' => 'nullable|string|max:100',
                'tags'     => 'nullable|array',
            ]);
            $id = app(VectorStoreService::class)->store(
                $data['title'], $data['content'],
                $data['tags'] ?? [], $data['category'] ?? 'general'
            );
            return response()->json(['id' => $id], 201);
        });

        // ── System Health ────────────────────────────────────────────
        Route::get('/health', function () {
            $status = app(SupervisorService::class)->getHealthStatus();
            return response()->json($status);
        });

        // ── AI Cost Report ───────────────────────────────────────────
        Route::get('/ai-costs', function () {
            $days = (int) request('days', 30);
            $rows = \App\Models\AiRequest::selectRaw(
                "provider, model, COUNT(*) as requests, SUM(tokens_in) as total_tokens_in,
                 SUM(tokens_out) as total_tokens_out, ROUND(SUM(cost_usd)::numeric, 4) as total_cost_usd"
            )
            ->where('requested_at', '>=', now()->subDays($days))
            ->groupBy('provider', 'model')
            ->orderByDesc('total_cost_usd')
            ->get();

            return response()->json([
                'period_days'      => $days,
                'total_usd'        => round($rows->sum('total_cost_usd'), 4),
                'breakdown'        => $rows,
            ]);
        });

    });
});
