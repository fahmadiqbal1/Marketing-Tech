<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookAuthService
{
    /**
     * Verify a Telegram webhook request using the X-Telegram-Bot-Api-Secret-Token header.
     * Telegram sends this header as a SHA-256 HMAC when a secret_token is set during webhook registration.
     */
    public function verifyTelegramWebhook(Request $request): bool
    {
        $expectedSecret = config('agents.telegram.webhook_secret');

        if (empty($expectedSecret)) {
            Log::warning('Telegram webhook secret not configured — accepting all requests');
            return true;
        }

        $receivedToken = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (empty($receivedToken)) {
            Log::warning('Telegram webhook request missing secret token header');
            return false;
        }

        // Constant-time comparison to prevent timing attacks
        return hash_equals($expectedSecret, $receivedToken);
    }
}
