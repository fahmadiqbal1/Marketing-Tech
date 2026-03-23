<?php

namespace App\Services\Social;

use App\Models\SocialCredential;

class CredentialResolver
{
    public function clientId(string $platform): ?string
    {
        $cred = SocialCredential::forPlatform($platform);
        if ($cred && ! empty($cred->client_id)) {
            return $cred->client_id;
        }

        $configKey = $platform === 'tiktok' ? 'client_key' : 'client_id';

        return config("services.{$platform}.{$configKey}") ?: null;
    }

    public function clientSecret(string $platform): ?string
    {
        $cred = SocialCredential::forPlatform($platform);
        if ($cred && ! empty($cred->client_secret)) {
            return $cred->client_secret;
        }

        return config("services.{$platform}.client_secret") ?: null;
    }

    public function redirectUri(string $platform): string
    {
        return config("services.{$platform}.redirect_uri")
            ?: (config('app.url') . "/dashboard/social/auth/{$platform}/callback");
    }

    public function extraConfig(string $platform): array
    {
        return SocialCredential::forPlatform($platform)?->extra_config ?? [];
    }
}
