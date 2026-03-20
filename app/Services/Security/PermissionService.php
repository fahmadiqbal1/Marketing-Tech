<?php

namespace App\Services\Security;

class PermissionService
{
    private array $permissions = [];

    public function __construct()
    {
        // In this platform, permissions are service-availability checks
        // e.g., 'use_openai', 'use_anthropic', 'use_ffmpeg', etc.
        $this->permissions = [
            'use_openai'     => ! empty(config('agents.openai.api_key')),
            'use_anthropic'  => ! empty(config('agents.anthropic.api_key')),
            'use_ffmpeg'     => file_exists(config('agents.media.ffmpeg', '/usr/bin/ffmpeg')),
            'use_imagemagick'=> file_exists('/usr/bin/convert'),
            'use_tesseract'  => file_exists(config('agents.media.tesseract', '/usr/bin/tesseract')),
            'use_clamav'     => true, // always attempt; ClamAVService handles unavailability
            'publish_jobs'   => true,
            'send_campaigns' => true,
        ];
    }

    public function has(string $permission): bool
    {
        return $this->permissions[$permission] ?? false;
    }

    public function all(): array
    {
        return $this->permissions;
    }
}
