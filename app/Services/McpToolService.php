<?php

namespace App\Services;

use App\Models\McpServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Execute tools on registered MCP servers.
 *
 * Supports two transports:
 *   - stdio  — spawns the server command as a subprocess, sends JSON-RPC over stdin
 *   - sse/http — calls the server's HTTP endpoint directly
 */
class McpToolService
{
    private const CACHE_TTL = 300; // 5 min capability cache

    /**
     * Execute a tool on a named MCP server.
     *
     * @throws \RuntimeException if the server is not found, inactive, or the call fails
     */
    public function execute(string $serverName, string $toolName, array $params = []): mixed
    {
        $server = $this->resolveServer($serverName);

        return match ($server->transport) {
            'stdio'       => $this->callStdio($server, $toolName, $params),
            'sse', 'http' => $this->callHttp($server, $toolName, $params),
            default       => throw new \RuntimeException("Unsupported MCP transport: {$server->transport}"),
        };
    }

    /**
     * List available tools for a server (cached).
     */
    public function listTools(string $serverName): array
    {
        return Cache::remember("mcp:tools:{$serverName}", self::CACHE_TTL, function () use ($serverName) {
            $server = $this->resolveServer($serverName);
            return $this->fetchCapabilities($server);
        });
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function resolveServer(string $name): McpServer
    {
        $server = McpServer::where('name', $name)->where('is_active', true)->first();

        if (! $server) {
            throw new \RuntimeException("MCP server '{$name}' not found or inactive.");
        }

        return $server;
    }

    /**
     * JSON-RPC call over stdio subprocess.
     * Spawns the server process, writes a single request, reads the response, then terminates.
     */
    private function callStdio(McpServer $server, string $toolName, array $params): mixed
    {
        $command = $server->command;
        if (empty($command)) {
            throw new \RuntimeException("MCP server '{$server->name}' has no command configured for stdio transport.");
        }

        $args = $server->args ?? [];
        $env  = array_merge($_ENV, $server->env_vars ?? []);

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => $toolName, 'arguments' => $params],
        ]);

        $fullCommand = trim($command . ' ' . implode(' ', array_map('escapeshellarg', $args)));

        try {
            $result = Process::timeout(30)
                ->env($env)
                ->input($request)
                ->run($fullCommand);

            if (! $result->successful()) {
                throw new \RuntimeException("MCP stdio process failed: " . $result->errorOutput());
            }

            $response = json_decode(trim($result->output()), true);
            return $this->extractResult($response, $server->name, $toolName);

        } catch (\Illuminate\Process\Exceptions\ProcessTimedOutException $e) {
            throw new \RuntimeException("MCP stdio call timed out for {$server->name}::{$toolName}");
        }
    }

    /**
     * JSON-RPC call over HTTP (SSE or plain HTTP transport).
     */
    private function callHttp(McpServer $server, string $toolName, array $params): mixed
    {
        $url = rtrim($server->url ?? '', '/') . '/tools/call';

        if (empty($server->url)) {
            throw new \RuntimeException("MCP server '{$server->name}' has no URL configured for HTTP transport.");
        }

        $payload = [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => $toolName, 'arguments' => $params],
        ];

        $response = Http::timeout(30)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $payload);

        if ($response->failed()) {
            throw new \RuntimeException("MCP HTTP call failed ({$response->status()}) for {$server->name}::{$toolName}");
        }

        return $this->extractResult($response->json(), $server->name, $toolName);
    }

    private function extractResult(mixed $response, string $serverName, string $toolName): mixed
    {
        if (! is_array($response)) {
            throw new \RuntimeException("MCP server '{$serverName}' returned non-JSON for tool '{$toolName}'");
        }

        if (isset($response['error'])) {
            $msg = $response['error']['message'] ?? 'Unknown MCP error';
            throw new \RuntimeException("MCP tool error [{$serverName}::{$toolName}]: {$msg}");
        }

        return $response['result'] ?? $response;
    }

    private function fetchCapabilities(McpServer $server): array
    {
        try {
            if ($server->transport === 'stdio') {
                $request = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
                $result  = Process::timeout(10)->input($request)->run($server->command ?? '');
                $data    = json_decode(trim($result->output()), true);
            } else {
                $response = Http::timeout(10)->post(rtrim($server->url ?? '', '/') . '/tools/list', [
                    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
                ]);
                $data = $response->json();
            }

            $tools = $data['result']['tools'] ?? [];
            McpServer::where('id', $server->id)->update([
                'capabilities'   => ['tools' => $tools],
                'last_synced_at' => now(),
            ]);

            return $tools;
        } catch (\Throwable $e) {
            Log::warning("McpToolService: capability fetch failed for {$server->name}", ['error' => $e->getMessage()]);
            return $server->capabilities['tools'] ?? [];
        }
    }
}
