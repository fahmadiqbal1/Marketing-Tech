<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

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
                \Illuminate\Support\Facades\Log::error('supervisor:tick failed');
            });

        // Sync skills registry to DB — every hour
        $schedule->command('skills:sync')
            ->hourly()
            ->withoutOverlapping();

        // Prune old workflow logs — daily at 2am
        $schedule->call(function () {
            \App\Models\WorkflowLog::where('logged_at', '<', now()->subDays(30))->delete();
            \App\Models\AiRequest::where('requested_at', '<', now()->subDays(90))->delete();
            \App\Models\SystemEvent::where('occurred_at', '<', now()->subDays(30))->delete();
        })->dailyAt('02:00')->name('prune_old_records')->withoutOverlapping();

        // Check for scheduled campaigns — every 5 minutes
        $schedule->call(function () {
            \App\Models\Campaign::where('status', 'scheduled')
                ->where('schedule_at', '<=', now())
                ->each(function ($campaign) {
                    app(\App\Services\Marketing\CampaignService::class)->sendCampaign($campaign);
                });
        })->everyFiveMinutes()->name('send_scheduled_campaigns')->withoutOverlapping();

        // Auto-run pending experiments analysis — every 15 minutes
        $schedule->call(function () {
            \App\Models\Experiment::where('status', 'running')
                ->where('current_sample_size', '>=', \Illuminate\Support\Facades\DB::raw('min_sample_size'))
                ->each(function ($experiment) {
                    app(\App\Services\Growth\ExperimentationEngine::class)->analyze($experiment);
                });
        })->everyFifteenMinutes()->name('analyze_experiments')->withoutOverlapping();

        // Decay context graph relevance scores — weekly
        $schedule->call(function () {
            \Illuminate\Support\Facades\DB::statement(
                "UPDATE context_graph_nodes SET relevance_decay = GREATEST(0.1, relevance_decay * 0.95) WHERE created_at < NOW() - INTERVAL '7 days'"
            );
        })->weekly()->name('decay_context_relevance');
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
