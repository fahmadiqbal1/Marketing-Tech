<?php

namespace Tests;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\RateLimiter;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api');
        // Disable throttle and CSRF middleware in tests
        $this->withoutMiddleware([
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }

    /**
     * Return the Authorization header value for DashboardBasicAuth.
     *
     * Reads DASHBOARD_USERNAME / DASHBOARD_PASSWORD from the environment so the
     * value always matches what phpunit.xml injects (no hard-coded duplication).
     */
    protected function dashboardAuthHeader(): string
    {
        $user = env('DASHBOARD_USERNAME', 'testuser');
        $pass = env('DASHBOARD_PASSWORD', 'testpass');

        return 'Basic ' . base64_encode("{$user}:{$pass}");
    }

    /**
     * Convenience wrapper: GET a dashboard route with Basic Auth pre-applied.
     */
    protected function dashboardGet(string $uri, array $headers = [])
    {
        return $this->withHeaders(array_merge(
            ['Authorization' => $this->dashboardAuthHeader()],
            $headers,
        ))->getJson($uri);
    }

    /**
     * Convenience wrapper: POST a dashboard route with Basic Auth pre-applied.
     */
    protected function dashboardPost(string $uri, array $data = [], array $headers = [])
    {
        return $this->withHeaders(array_merge(
            ['Authorization' => $this->dashboardAuthHeader()],
            $headers,
        ))->postJson($uri, $data);
    }

    /**
     * Convenience wrapper: PUT a dashboard route with Basic Auth pre-applied.
     */
    protected function dashboardPut(string $uri, array $data = [], array $headers = [])
    {
        return $this->withHeaders(array_merge(
            ['Authorization' => $this->dashboardAuthHeader()],
            $headers,
        ))->putJson($uri, $data);
    }

    /**
     * Convenience wrapper: DELETE a dashboard route with Basic Auth pre-applied.
     */
    protected function dashboardDelete(string $uri, array $headers = [])
    {
        return $this->withHeaders(array_merge(
            ['Authorization' => $this->dashboardAuthHeader()],
            $headers,
        ))->deleteJson($uri);
    }

    /**
     * Create a Business + admin User and authenticate as them.
     * Useful for tests that need session-based authentication.
     */
    protected function asBusiness(?Business $business = null, ?User $user = null): static
    {
        $business ??= Business::factory()->create();
        $user     ??= User::factory()->create(['business_id' => $business->id]);

        return $this->actingAs($user);
    }
}
