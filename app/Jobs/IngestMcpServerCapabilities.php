<?php

namespace App\Jobs;

use App\Models\KnowledgeBase;
use App\Models\McpServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IngestMcpServerCapabilities implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries   = 2;
    public int    $timeout = 120;
    public string $queue   = 'low';

    public function __construct(private readonly string $serverId) {}

    public function handle(): void
    {
        $server = McpServer::find($this->serverId);
        if (! $server || ! $server->is_active) {
            return;
        }

        if ($server->transport === 'stdio') {
            // Subprocess MCP requires CLI tooling — not supported in queue workers
            Log::info('IngestMcpServerCapabilities: skipping stdio transport', ['id' => $this->serverId]);
            return;
        }

        // For sse/http: POST {url}/tools/list
        $url = rtrim($server->url, '/') . '/tools/list';

        try {
            $response = Http::timeout(30)->post($url, []);

            if (! $response->ok()) {
                Log::warning('IngestMcpServerCapabilities: tools/list returned non-200', [
                    'url'    => $url,
                    'status' => $response->status(),
                ]);
                return;
            }

            $tools        = $response->json('tools', $response->json('result.tools', []));
            $capabilities = [];

            foreach ($tools as $tool) {
                $name        = $tool['name'] ?? null;
                $description = $tool['description'] ?? '';
                if (! $name) {
                    continue;
                }

                $capabilities[] = $name;

                KnowledgeBase::firstOrCreate(
                    ['title' => "MCP: {$server->name}: {$name}"],
                    [
                        'content'     => $description ?: "MCP tool '{$name}' from server '{$server->name}'.",
                        'category'    => 'agent-skills',
                        'tags'        => ['mcp', $server->name, $name],
                        'business_id' => $server->business_id,
                    ]
                );
            }

            $server->update([
                'capabilities'   => $capabilities,
                'last_synced_at' => now(),
            ]);

            Log::info('IngestMcpServerCapabilities: ingested', [
                'server' => $server->name,
                'tools'  => count($capabilities),
            ]);
        } catch (\Throwable $e) {
            Log::warning('IngestMcpServerCapabilities: failed', [
                'server' => $server->name,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('IngestMcpServerCapabilities permanently failed', [
            'server_id' => $this->serverId,
            'error'     => $e->getMessage(),
        ]);
    }
}
