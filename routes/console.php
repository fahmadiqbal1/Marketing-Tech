<?php

use App\Jobs\AutoReplenishContent;
use App\Jobs\PruneRejectedCandidates;
use App\Jobs\RefreshCredentialStatus;
use App\Jobs\DispatchScheduledPosts;
use App\Jobs\FetchSocialMetrics;
use App\Jobs\ProcessTrends;
use App\Jobs\RefreshSocialTokens;
use App\Jobs\RepurposeContent;
use Illuminate\Support\Facades\Schedule;

// Prune old agent memories daily at 2am
Schedule::command('agent:prune-memories')->dailyAt('02:00');

// Social: check for due scheduled posts every minute (queue: social)
Schedule::job(new DispatchScheduledPosts, 'social')->everyMinute();

// Social: auto-replenish content calendar when platforms run low (queue: low)
Schedule::job(new AutoReplenishContent, 'low')->dailyAt('08:00');

// Social: fetch real metrics for published posts (queue: low)
Schedule::job(new FetchSocialMetrics, 'low')->dailyAt('06:00');

// Social: repurpose top-performing content across platforms (queue: low)
Schedule::job(new RepurposeContent, 'low')->weekly();

// Social: analyse knowledge base patterns and create draft trend content (queue: low)
Schedule::job(new ProcessTrends, 'low')->everyFourHours();

// Social: refresh OAuth tokens expiring within 24h (queue: low)
Schedule::job(new RefreshSocialTokens, 'low')->dailyAt('03:00');

// Hiring: purge rejected candidates older than 30 days (GDPR/retention, queue: low)
Schedule::job(new PruneRejectedCandidates, 'low')->dailyAt('01:00');

// Social credentials: nightly health check — deactivate stale creds (queue: low)
Schedule::job(new RefreshCredentialStatus, 'low')->dailyAt('02:00');
