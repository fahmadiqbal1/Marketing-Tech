<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional HTTP Basic Auth for the dashboard.
 *
 * Enabled when both DASHBOARD_USERNAME and DASHBOARD_PASSWORD env vars are set.
 * If either is empty the middleware is a no-op (open access, useful for dev).
 *
 * Session-authenticated users (via the new login system) always pass through
 * so both auth methods work side by side.
 */
class DashboardBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Session-authenticated users always pass through
        if (Auth::check()) {
            return $next($request);
        }

        $username = config('dashboard.username');
        $password = config('dashboard.password');

        // No credentials configured → allow through (dev mode)
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
