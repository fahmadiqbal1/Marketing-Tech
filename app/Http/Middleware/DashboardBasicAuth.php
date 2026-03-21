<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional HTTP Basic Auth for the dashboard.
 *
 * Enabled when both DASHBOARD_USERNAME and DASHBOARD_PASSWORD env vars are set.
 * If either is empty the middleware is a no-op (open access, useful for dev).
 */
class DashboardBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = env('DASHBOARD_USERNAME');
        $password = env('DASHBOARD_PASSWORD');

        // No credentials configured → allow through
        if (empty($username) || empty($password)) {
            return $next($request);
        }

        // Check Authorization header
        if (
            $request->getUser() === $username &&
            $request->getPassword() === $password
        ) {
            return $next($request);
        }

        return response('Unauthorized', 401, [
            'WWW-Authenticate' => 'Basic realm="Marketing OS Dashboard"',
        ]);
    }
}
