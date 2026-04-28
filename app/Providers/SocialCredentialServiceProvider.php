<?php

namespace App\Providers;

use App\Services\ApiCredentialService;
use Illuminate\Support\ServiceProvider;

class SocialCredentialServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            $creds = $this->app->make(ApiCredentialService::class);

            $map = [
                'SOCIAL_TWITTER_CLIENT_ID'       => 'services.twitter.client_id',
                'SOCIAL_TWITTER_CLIENT_SECRET'   => 'services.twitter.client_secret',
                'SOCIAL_TWITTER_BEARER_TOKEN'    => 'services.twitter.bearer_token',
                'SOCIAL_INSTAGRAM_CLIENT_ID'     => 'services.instagram.client_id',
                'SOCIAL_INSTAGRAM_CLIENT_SECRET' => 'services.instagram.client_secret',
                'SOCIAL_LINKEDIN_CLIENT_ID'      => 'services.linkedin.client_id',
                'SOCIAL_LINKEDIN_CLIENT_SECRET'  => 'services.linkedin.client_secret',
                'SOCIAL_FACEBOOK_CLIENT_ID'      => 'services.facebook.client_id',
                'SOCIAL_FACEBOOK_CLIENT_SECRET'  => 'services.facebook.client_secret',
                'SOCIAL_TIKTOK_CLIENT_KEY'       => 'services.tiktok.client_key',
                'SOCIAL_TIKTOK_CLIENT_SECRET'    => 'services.tiktok.client_secret',
                'SOCIAL_YOUTUBE_CLIENT_ID'       => 'services.youtube.client_id',
                'SOCIAL_YOUTUBE_CLIENT_SECRET'   => 'services.youtube.client_secret',
            ];

            $overrides = [];
            foreach ($map as $envKey => $configPath) {
                $val = $creds->retrieve($envKey);
                if ($val !== null) {
                    $overrides[$configPath] = $val;
                }
            }

            $telegramToken = $creds->retrieve('TELEGRAM_BOT_TOKEN');
            if ($telegramToken !== null) {
                $overrides['agents.telegram.token'] = $telegramToken;
            }

            if ($overrides) {
                config($overrides);
            }
        } catch (\Throwable) {
            // DB unavailable (e.g. during php artisan migrate) — env() fallback remains active
        }
    }
}
