<?php

namespace App\Console;

use App\Models\AiRequest;
use App\Models\Campaign;
use App\Models\Experiment;
use App\Models\SystemEvent;
use App\Models\WorkflowLog;
use App\Services\Growth\ExperimentationEngine;
use App\Services\Marketing\CampaignService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        Commands\SupervisorTickCommand::class,
        Commands\SyncSkillsRegistryCommand::class,
        Commands\RegisterTelegramWebhookCommand::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // Supervisor monitoring — every minute
        $schedule->command('supervisor:tick')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground()
            ->onFailure(function () {
                Log::error('supervisor:tick failed');
            });

        // Sync skills registry to DB — every hour
        $schedule->command('skills:sync')
            ->hourly()
            ->withoutOverlapping();

        // Prune old workflow logs — daily at 2am
        $schedule->call(function () {
            WorkflowLog::where('logged_at', '<', now()->subDays(30))->delete();
            AiRequest::where('requested_at', '<', now()->subDays(90))->delete();
            SystemEvent::where('occurred_at', '<', now()->subDays(30))->delete();
        })->dailyAt('02:00')->name('prune_old_records')->withoutOverlapping();

        // Check for scheduled campaigns — every 5 minutes
        $schedule->call(function () {
            Campaign::where('status', 'scheduled')
                ->where('schedule_at', '<=', now())
                ->each(function ($campaign) {
                    app(CampaignService::class)->sendCampaign($campaign);
                });
        })->everyFiveMinutes()->name('send_scheduled_campaigns')->withoutOverlapping();

        // Auto-run pending experiments analysis — every 15 minutes
        $schedule->call(function () {
            Experiment::where('status', 'running')
                ->where('current_sample_size', '>=', DB::raw('min_sample_size'))
                ->each(function ($experiment) {
                    app(ExperimentationEngine::class)->analyze($experiment);
                });
        })->everyFifteenMinutes()->name('analyze_experiments')->withoutOverlapping();

        // Decay context graph relevance scores — weekly
        $schedule->call(function () {
            DB::table('context_graph_nodes')
                ->where('created_at', '<', now()->subDays(7))
                ->update([
                    'relevance_decay' => DB::raw('GREATEST(0.1, relevance_decay * 0.95)'),
                ]);
        })->weekly()->name('decay_context_relevance');
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
