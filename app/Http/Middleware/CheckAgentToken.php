<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional token-based access guard for agent endpoints.
 *
 * If AGENT_ACCESS_TOKEN is set in config (agent_system.access_token),
 * all requests must include the matching header:
 *   X-Agent-Token: <token>
 *
 * If the config value is empty, this middleware is a no-op (open access).
 */
class CheckAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('agent_system.access_token');

        // No token configured → open access (no-op)
        if (empty($configuredToken)) {
            return $next($request);
        }

        $providedToken = $request->header('X-Agent-Token');

        if (! $providedToken || ! hash_equals($configuredToken, $providedToken)) {
            return response()->json(['error' => 'Unauthorized. Provide a valid X-Agent-Token header.'], 401);
        }

        return $next($request);
    }
}
