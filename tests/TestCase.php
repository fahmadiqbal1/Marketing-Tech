<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\RateLimiter;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api');
        // Disable all throttle middleware in tests to avoid 429s
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
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
}
