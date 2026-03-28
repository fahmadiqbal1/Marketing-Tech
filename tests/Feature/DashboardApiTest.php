<?php

namespace Tests\Feature;

use App\Models\AgentJob;
use App\Models\Campaign;
use App\Models\SystemEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DashboardApiTest
 *
 * Covers:
 *  - DashboardBasicAuth middleware (pass / fail)
 *  - GET /dashboard/api/stats    — returns expected JSON shape
 *  - GET /dashboard/api/jobs     — returns jobs array + top_failure_reason key
 *  - POST /dashboard/api/campaigns — creates campaign, validates required fields
 *  - POST /dashboard/api/campaigns/{id}/pause  — toggles status
 *  - POST /dashboard/api/campaigns/{id}/resume — toggles status
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    // ─── Auth middleware ──────────────────────────────────────────────────────

    public function test_dashboard_api_requires_basic_auth(): void
    {
        $response = $this->getJson('/dashboard/api/stats');

        // When credentials are configured the middleware returns 401 without them
        $response->assertStatus(401);
    }

    public function test_dashboard_api_accepts_correct_basic_auth(): void
    {
        $response = $this->dashboardGet('/dashboard/api/stats');

        // Any 2xx means auth passed and the endpoint executed
        $response->assertSuccessful();
    }

    public function test_dashboard_api_rejects_wrong_password(): void
    {
        $wrongHeader = 'Basic ' . base64_encode('testuser:wrongpassword');

        $response = $this->withHeaders(['Authorization' => $wrongHeader])
            ->getJson('/dashboard/api/stats');

        $response->assertStatus(401);
    }

    // ─── GET /dashboard/api/stats ─────────────────────────────────────────────

    public function test_api_stats_returns_expected_shape(): void
    {
        $response = $this->dashboardGet('/dashboard/api/stats');

        $response->assertSuccessful()
            ->assertJsonStructure([
                // DashboardStatsService::getStats() returns these top-level keys
                'active_jobs',
                'failed_jobs',
                'needs_approval',
                'queue_depth',
                'ai_cost_today',
                'ai_cost_week',
                'recent_workflows',
                'recent_events',
            ]);
    }

    // ─── GET /dashboard/api/jobs ──────────────────────────────────────────────

    public function test_api_jobs_returns_jobs_with_failure_reason_key(): void
    {
        // Seed a failed job so top_failure_reason is populated (HasUuids auto-assigns id)
        AgentJob::create([
            'agent_type'        => 'content',
            'agent_class'       => 'App\\Agents\\ContentAgent',
            'instruction'       => 'Write a blog post',
            'status'            => 'failed',
            'error_message'     => 'OpenAI rate limit exceeded',
            'steps_taken'       => 2,
            'total_tokens'      => 0,
            'metadata'          => [],
        ]);

        $response = $this->dashboardGet('/dashboard/api/jobs');

        $response->assertSuccessful()
            ->assertJsonStructure(['top_failure_reason']);

        // top_failure_reason should be non-null because we seeded a failed job
        $this->assertNotNull($response->json('top_failure_reason'));
    }

    public function test_api_jobs_top_failure_reason_is_null_when_no_failures(): void
    {
        $response = $this->dashboardGet('/dashboard/api/jobs');

        $response->assertSuccessful();
        $this->assertNull($response->json('top_failure_reason'));
    }

    // ─── POST /dashboard/api/campaigns ───────────────────────────────────────

    public function test_create_campaign_succeeds_with_valid_data(): void
    {
        $payload = [
            'name'     => 'Summer Launch',
            'type'     => 'email',
            'audience' => 'newsletter_subscribers',
            'subject'  => 'Big summer deals inside',
        ];

        $response = $this->dashboardPost('/dashboard/api/campaigns', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'Summer Launch'])
            ->assertJsonFragment(['status' => 'draft']);

        $this->assertDatabaseHas('campaigns', ['name' => 'Summer Launch', 'type' => 'email']);
    }

    public function test_create_campaign_requires_name(): void
    {
        $response = $this->dashboardPost('/dashboard/api/campaigns', [
            'type' => 'email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_campaign_requires_valid_type(): void
    {
        $response = $this->dashboardPost('/dashboard/api/campaigns', [
            'name' => 'Test Campaign',
            'type' => 'carrier_pigeon',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // ─── POST /dashboard/api/campaigns/{id}/pause ────────────────────────────

    public function test_pause_campaign_sets_status_to_paused(): void
    {
        // HasUuids auto-assigns a UUID — do not pass 'id' through $fillable
        $campaign = Campaign::create([
            'name'   => 'Active Campaign',
            'type'   => 'social',
            'status' => 'active',
        ]);

        $response = $this->dashboardPost("/dashboard/api/campaigns/{$campaign->id}/pause");

        $response->assertSuccessful()
            ->assertJsonFragment(['status' => 'paused']);

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'paused']);
    }

    // ─── POST /dashboard/api/campaigns/{id}/resume ───────────────────────────

    public function test_resume_campaign_sets_status_to_active(): void
    {
        $campaign = Campaign::create([
            'name'   => 'Paused Campaign',
            'type'   => 'social',
            'status' => 'paused',
        ]);

        $response = $this->dashboardPost("/dashboard/api/campaigns/{$campaign->id}/resume");

        $response->assertSuccessful()
            ->assertJsonFragment(['status' => 'active']);

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'active']);
    }

    // ─── GET /dashboard/api/system-events ────────────────────────────────────

    public function test_api_system_events_returns_list(): void
    {
        SystemEvent::create([
            'event_type' => 'test_event',
            'severity'   => 'info',
            'source'     => 'test',
            'message'    => 'Test event for unit test',
            'occurred_at'=> now(),
        ]);

        $response = $this->dashboardGet('/dashboard/api/system-events');

        $response->assertSuccessful()
            ->assertJsonStructure(['data']);
    }
}
