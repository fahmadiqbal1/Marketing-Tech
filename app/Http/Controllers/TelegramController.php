<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramBotService;
use App\Services\Security\WebhookAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    public function __construct(
        private readonly TelegramBotService  $bot,
        private readonly WebhookAuthService  $auth,
    ) {}

    /**
     * Handle incoming Telegram webhook update.
     * Telegram requires a 200 OK within 10 seconds — we dispatch async.
     */
    public function webhook(Request $request): JsonResponse
    {
        // Verify HMAC signature from Telegram
        if (! $this->auth->verifyTelegramWebhook($request)) {
            Log::warning('Telegram webhook signature mismatch', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['ok' => false], 401);
        }

        $update = $request->all();

        // Validate payload has an update_id
        if (empty($update['update_id'])) {
            return response()->json(['ok' => true]);
        }

        // Extract chat_id before try so we can notify on failure
        $chatId = $update['message']['chat']['id']
            ?? $update['callback_query']['message']['chat']['id']
            ?? null;

        try {
            // Dispatch to async handler — never block the webhook
            $this->bot->handleUpdate($update);
        } catch (\Throwable $e) {
            // Log but always return 200 to Telegram (prevents retries for app errors)
            Log::error('Telegram webhook dispatch error', [
                'error'     => $e->getMessage(),
                'update_id' => $update['update_id'],
            ]);
            // Notify the user so they know their command failed
            if ($chatId) {
                try {
                    $this->bot->sendMessage((string) $chatId, "⚠️ Sorry, an internal error occurred processing your request. Please try again.");
                } catch (\Throwable) {
                    // Suppress — don't let notification failure affect response
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Register the webhook URL with Telegram.
     * Call this once when deploying or changing the app URL.
     */
    public function register(Request $request): JsonResponse
    {
        // Only callable from authenticated admin sessions
        $webhookUrl    = config('app.url') . '/webhook/telegram';
        $secretToken   = config('agents.telegram.webhook_secret');

        $result = $this->bot->registerWebhook($webhookUrl, $secretToken);

        return response()->json([
            'ok'      => $result['ok'] ?? false,
            'message' => $result['description'] ?? 'Webhook registered',
            'url'     => $webhookUrl,
        ]);
    }

    /**
     * Get current webhook info from Telegram.
     */
    public function info(): JsonResponse
    {
        $info = $this->bot->getWebhookInfo();
        return response()->json($info);
    }
}
