<?php

namespace Tests\Feature;

use App\Services\Telegram\TelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TelegramWebhookTest
 *
 * Covers WebhookAuthService::verifyTelegramWebhook():
 *  - Correct X-Telegram-Bot-Api-Secret-Token header → 200 OK
 *  - Missing header → 401
 *  - Wrong token → 401
 *  - No secret configured → accepts all requests (no-op mode)
 *  - Missing update_id in payload → returns ok:true without dispatching
 *  - Valid payload dispatches to TelegramBotService
 *
 * The Telegram webhook secret is set in phpunit.xml:
 *   TELEGRAM_WEBHOOK_SECRET=test-webhook-secret-token
 *
 * WebhookAuthService does a plain hash_equals() comparison against the
 * X-Telegram-Bot-Api-Secret-Token header value — NOT an HMAC over the body.
 * The test therefore just sends the raw secret string as the header value.
 */
class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;
    private string $webhookUri = '/api/webhook/telegram';

    protected function setUp(): void
    {
        parent::setUp();

        // Read the secret that phpunit.xml injects via TELEGRAM_WEBHOOK_SECRET
        // and that the config('agents.telegram.webhook_secret') path resolves to.
        $this->secret = config('agents.telegram.webhook_secret', 'test-webhook-secret-token');

        // Always stub TelegramBotService to prevent the container from resolving
        // the full AgentOrchestrator + all 6 agents dependency tree in every test.
        $this->mock(TelegramBotService::class, function ($mock) {
            $mock->shouldReceive('handleUpdate')->zeroOrMoreTimes();
        });
    }

    /**
     * Build a minimal valid Telegram update payload.
     */
    private function telegramPayload(array $overrides = []): array
    {
        return array_merge([
            'update_id' => 123456789,
            'message'   => [
                'message_id' => 1,
                'from'       => ['id' => 111222333, 'first_name' => 'TestUser', 'is_bot' => false],
                'chat'       => ['id' => 111222333, 'type' => 'private'],
                'date'       => time(),
                'text'       => 'Hello bot',
            ],
        ], $overrides);
    }

    // ─── Auth verification ────────────────────────────────────────────────────

    public function test_webhook_rejects_request_without_secret_header(): void
    {
        $response = $this->postJson($this->webhookUri, $this->telegramPayload());

        $response->assertStatus(401)
            ->assertJson(['ok' => false]);
    }

    public function test_webhook_rejects_request_with_wrong_secret(): void
    {
        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'completely-wrong-secret',
        ])->postJson($this->webhookUri, $this->telegramPayload());

        $response->assertStatus(401)
            ->assertJson(['ok' => false]);
    }

    public function test_webhook_accepts_request_with_correct_secret(): void
    {
        // Mock TelegramBotService so we don't need a real Telegram token or DB chain
        $this->mock(TelegramBotService::class, function ($mock) {
            $mock->shouldReceive('handleUpdate')->once();
        });

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => $this->secret,
        ])->postJson($this->webhookUri, $this->telegramPayload());

        $response->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    // ─── Payload edge cases ───────────────────────────────────────────────────

    public function test_webhook_returns_ok_true_for_payload_missing_update_id(): void
    {
        // No update_id → controller returns early without calling handleUpdate
        $this->mock(TelegramBotService::class, function ($mock) {
            $mock->shouldReceive('handleUpdate')->never();
        });

        $payload = $this->telegramPayload();
        unset($payload['update_id']);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => $this->secret,
        ])->postJson($this->webhookUri, $payload);

        $response->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_webhook_returns_200_even_when_bot_service_throws(): void
    {
        // The controller catches all Throwable and still returns 200 to prevent
        // Telegram from retrying on application errors.
        $this->mock(TelegramBotService::class, function ($mock) {
            $mock->shouldReceive('handleUpdate')
                ->once()
                ->andThrow(new \RuntimeException('Downstream failure'));
        });

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => $this->secret,
        ])->postJson($this->webhookUri, $this->telegramPayload());

        $response->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    // ─── No-secret (open) mode ────────────────────────────────────────────────

    public function test_webhook_accepts_any_request_when_no_secret_configured(): void
    {
        // Temporarily remove the secret from config
        config(['agents.telegram.webhook_secret' => null]);

        $this->mock(TelegramBotService::class, function ($mock) {
            $mock->shouldReceive('handleUpdate')->once();
        });

        // No header — should still pass because secret is empty → middleware is no-op
        $response = $this->postJson($this->webhookUri, $this->telegramPayload());

        $response->assertStatus(200)
            ->assertJson(['ok' => true]);
    }
}
