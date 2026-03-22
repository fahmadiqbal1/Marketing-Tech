<?php

namespace App\Jobs;

use App\Services\Knowledge\VectorStoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IngestGitHubRepo — queued job that ingests files from a public or private
 * GitHub repository into the knowledge base.
 *
 * Safety limits (all configurable via constructor):
 *  - Max files per run: 200
 *  - Max file size: 200 KB per file
 *  - Max total chars per repo: 200,000
 *  - Max entries per repo: 500
 *  - Rate limiting: pauses when GitHub rate limit < 10 remaining
 *
 * Queued on the 'low' queue to avoid blocking agent jobs ('high' queue).
 * Per-file errors are logged and skipped — one bad file won't kill the import.
 */
class IngestGitHubRepo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;
    public array $backoff = [30, 120];

    private const MAX_FILES       = 200;
    private const MAX_FILE_SIZE   = 200 * 1024;  // 200 KB in bytes
    private const MAX_TOTAL_CHARS = 200_000;
    private const MAX_ENTRIES     = 500;

    private const ALLOWED_EXTENSIONS = ['md', 'txt', 'php', 'js', 'ts', 'py', 'json', 'yaml', 'yml'];

    private const SKIP_DIRS = ['vendor/', 'node_modules/', 'dist/', '.git/', 'coverage/', '.next/', 'build/'];

    public function __construct(
        private readonly string  $repoUrl,
        private readonly string  $category    = 'general',
        private readonly array   $extensions  = self::ALLOWED_EXTENSIONS,
        private readonly ?string $githubToken = null,
        private readonly ?string $branch      = 'main',
    ) {
        $this->onQueue('low');
    }

    public function handle(VectorStoreService $vectorStore): void
    {
        ['owner' => $owner, 'repo' => $repo] = $this->parseRepoUrl();

        $headers = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($this->githubToken) {
            $headers['Authorization'] = "Bearer {$this->githubToken}";
        }

        // Fetch the repository file tree
        $branch    = $this->resolveBranch($owner, $repo, $headers);
        $treeUrl   = "https://api.github.com/repos/{$owner}/{$repo}/git/trees/{$branch}?recursive=1";
        $treeResp  = Http::withHeaders($headers)->timeout(30)->get($treeUrl);

        if ($treeResp->failed()) {
            Log::error("IngestGitHubRepo: failed to fetch tree", [
                'repo'   => "{$owner}/{$repo}",
                'status' => $treeResp->status(),
            ]);
            $this->fail(new \RuntimeException("GitHub tree fetch failed: HTTP {$treeResp->status()}"));
            return;
        }

        $tree = $treeResp->json('tree') ?? [];

        // Filter to relevant files
        $files = $this->filterFiles($tree);

        $ingested    = 0;
        $skipped     = 0;
        $failed      = 0;
        $failedFiles = [];
        $totalChars  = 0;
        $total       = count($files);
        $cacheKey    = 'github-import:' . md5($this->normalizeRepoUrl($this->repoUrl));

        Cache::put($cacheKey, [
            'status'     => 'running',
            'repo'       => "{$owner}/{$repo}",
            'ingested'   => 0,
            'total'      => $total,
            'started_at' => now()->toISOString(),
        ], now()->addHours(6));

        foreach ($files as $file) {
            if ($ingested >= self::MAX_FILES || $ingested + $skipped >= self::MAX_ENTRIES) {
                Log::info("IngestGitHubRepo: limit reached, stopping", ['ingested' => $ingested]);
                break;
            }
            if ($totalChars >= self::MAX_TOTAL_CHARS) {
                Log::info("IngestGitHubRepo: total char limit reached", ['chars' => $totalChars]);
                break;
            }

            // Respect GitHub rate limit
            $this->checkRateLimit($headers);

            try {
                $rawUrl  = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$file['path']}";
                $content = Http::withHeaders($headers)->timeout(15)->get($rawUrl)->body();

                if (strlen($content) > self::MAX_FILE_SIZE) {
                    Log::debug("IngestGitHubRepo: skipping oversized file", ['path' => $file['path'], 'size' => strlen($content)]);
                    $skipped++;
                    continue;
                }

                if (empty(trim($content))) {
                    $skipped++;
                    continue;
                }

                $title    = "{$owner}/{$repo}/{$file['path']}";
                $category = $this->categorise($file['path']);

                // Content-based override: if file contains skill manifest markers,
                // treat it as agent-skills regardless of path
                $tags = ['github', $repo, $owner];
                if ($category !== 'agent-skills' && $this->isSkillManifest($content)) {
                    $category = 'agent-skills';
                    $tags[]   = 'skills';
                }

                $vectorStore->store(
                    title:    $title,
                    content:  $content,
                    tags:     $tags,
                    category: $category,
                    source:   $rawUrl,
                );

                $totalChars += strlen($content);
                $ingested++;

                // Update progress every 5 files
                if ($ingested % 5 === 0) {
                    Cache::put($cacheKey, [
                        'status'   => 'running',
                        'repo'     => "{$owner}/{$repo}",
                        'ingested' => $ingested,
                        'total'    => $total,
                    ], now()->addHours(6));
                }

            } catch (\Throwable $e) {
                // Per-file error: log and continue — one bad file won't kill the import
                Log::warning("IngestGitHubRepo: file failed", [
                    'path'  => $file['path'],
                    'error' => $e->getMessage(),
                ]);
                $failedFiles[] = ['path' => $file['path'], 'error' => $e->getMessage()];
                $failed++;
            }
        }

        Cache::put($cacheKey, [
            'status'       => 'completed',
            'repo'         => "{$owner}/{$repo}",
            'ingested'     => $ingested,
            'total'        => $total,
            'skipped'      => $skipped,
            'failed'       => $failed,
            'failed_files' => array_slice($failedFiles, 0, 20),
            'completed_at' => now()->toISOString(),
        ], now()->addHours(6));

        Log::info("IngestGitHubRepo: completed", [
            'repo'      => "{$owner}/{$repo}",
            'ingested'  => $ingested,
            'skipped'   => $skipped,
            'failed'    => $failed,
            'chars'     => $totalChars,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $cacheKey = 'github-import:' . md5($this->normalizeRepoUrl($this->repoUrl));
        $existing = Cache::get($cacheKey, []);
        Cache::put($cacheKey, array_merge($existing, [
            'status' => 'failed',
            'error'  => $exception->getMessage(),
        ]), now()->addHours(6));
    }

    // ── Private helpers ───────────────────────────────────────────────

    private function parseRepoUrl(): array
    {
        // Support: https://github.com/owner/repo or github.com/owner/repo
        $clean = preg_replace('#^https?://(www\.)?github\.com/#', '', $this->repoUrl);
        $clean = trim($clean, '/');
        $parts = explode('/', $clean);

        if (count($parts) < 2) {
            throw new \InvalidArgumentException("Invalid GitHub URL: {$this->repoUrl}");
        }

        return ['owner' => $parts[0], 'repo' => $parts[1]];
    }

    private function resolveBranch(string $owner, string $repo, array $headers): string
    {
        if ($this->branch !== 'main') {
            return $this->branch;
        }

        // Try main first, then master as fallback
        foreach (['main', 'master'] as $candidate) {
            $resp = Http::withHeaders($headers)->timeout(10)
                ->get("https://api.github.com/repos/{$owner}/{$repo}/branches/{$candidate}");
            if ($resp->successful()) {
                return $candidate;
            }
        }

        return 'main';
    }

    private function filterFiles(array $tree): array
    {
        return array_filter($tree, function ($item) {
            if (($item['type'] ?? '') !== 'blob') {
                return false;
            }

            $path = $item['path'] ?? '';

            // Skip blacklisted directories
            foreach (self::SKIP_DIRS as $dir) {
                if (str_starts_with($path, $dir) || str_contains($path, "/{$dir}")) {
                    return false;
                }
            }

            // Check allowed extension
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            return in_array($ext, $this->extensions, true);
        });
    }

    private function categorise(string $path): string
    {
        $lower    = strtolower($path);
        $basename = strtolower(basename($path));

        // Priority: skill/agent definition files → agent-skills category
        $skillBasenames = ['skills.md', 'skills.yaml', 'skills.json', 'agents.yaml', 'agents.json', 'agent-config.yaml', 'agent-config.json', 'claude.md'];
        if (in_array($basename, $skillBasenames, true)) {
            return 'agent-skills';
        }
        if ((str_contains($lower, 'skills') || str_contains($lower, 'agent')) && str_ends_with($lower, '.md')) {
            return 'agent-skills';
        }

        if (str_starts_with($lower, 'docs/') || str_contains($lower, '/docs/') || str_ends_with($lower, '.md') || str_contains($lower, 'readme')) {
            return 'general';
        }
        if (str_contains($lower, 'marketing') || str_contains($lower, 'campaign')) {
            return 'marketing';
        }
        if (str_contains($lower, 'content') || str_contains($lower, 'blog')) {
            return 'content';
        }

        return 'technical';
    }

    /**
     * Detect if file content follows AgentSkillsSeeder skill manifest format.
     * Returns true if the content has ROLE: + TOOLS: markers.
     */
    private function isSkillManifest(string $content): bool
    {
        return str_contains($content, 'ROLE:') && str_contains($content, 'TOOLS:');
    }

    /**
     * Normalize a GitHub repo URL so cache keys are consistent regardless of
     * trailing slashes, .git suffix, or casing differences.
     * e.g. https://github.com/Owner/Repo.git/ → https://github.com/owner/repo
     */
    private function normalizeRepoUrl(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('#\.git$#', '', $url);
        return rtrim($url, '/');
    }

    private function checkRateLimit(array $headers): void
    {
        static $callCount = 0;
        $callCount++;

        // Check rate limit every 25 files to avoid extra API calls
        if ($callCount % 25 !== 0) {
            return;
        }

        $resp = Http::withHeaders($headers)->timeout(5)->get('https://api.github.com/rate_limit');
        if ($resp->successful()) {
            $remaining = $resp->json('rate.remaining') ?? 999;
            if ($remaining < 10) {
                $resetAt = $resp->json('rate.reset') ?? (time() + 60);
                $sleep   = max(1, $resetAt - time() + 5);
                Log::warning("IngestGitHubRepo: rate limit low, sleeping", ['remaining' => $remaining, 'sleep' => $sleep]);
                sleep(min($sleep, 120)); // cap sleep at 2 minutes
            }
        }
    }
}
