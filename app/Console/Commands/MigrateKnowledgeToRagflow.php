<?php

namespace App\Console\Commands;

use App\Models\KnowledgeBase;
use App\Services\Knowledge\RAGFlowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MigrateKnowledgeToRagflow extends Command
{
    protected $signature   = 'knowledge:migrate-to-ragflow {--chunk=50 : Batch size} {--dry-run : Preview without writing}';
    protected $description = 'Upload existing knowledge_base entries to RAGFlow for semantic search';

    private array $datasetCache = [];

    private array $chunkMethodMap = [
        'general'   => 'naive',
        'agent-skills' => 'qa',
        'marketing' => 'paper',
        'content'   => 'paper',
        'media'     => 'naive',
        'hiring'    => 'manual',
    ];

    public function handle(RAGFlowService $ragflow): int
    {
        if (! $ragflow->isEnabled()) {
            $this->error('RAGFlow is not enabled. Set RAGFLOW_ENABLED=true and RAGFLOW_API_KEY in .env');
            return 1;
        }

        if (! $ragflow->healthCheck()) {
            $this->error('RAGFlow API is not reachable. Check RAGFLOW_BASE_URL and that the container is running.');
            return 1;
        }

        $dryRun    = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        $total     = KnowledgeBase::whereNull('ragflow_doc_id')->whereNotNull('content')->count();
        $this->info("Found {$total} knowledge entries to migrate" . ($dryRun ? ' (dry-run)' : ''));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $ingested = 0;
        $failed   = 0;

        KnowledgeBase::whereNull('ragflow_doc_id')
            ->whereNotNull('content')
            ->chunkById($chunkSize, function ($entries) use ($ragflow, $dryRun, $bar, &$ingested, &$failed) {
                foreach ($entries as $entry) {
                    try {
                        $category    = $entry->category ?? 'general';
                        $chunkMethod = $this->chunkMethodMap[$category] ?? 'naive';

                        if ($dryRun) {
                            $this->line("\n  [DRY-RUN] Would upload: {$entry->title} → dataset:{$category} ({$chunkMethod})");
                            $ingested++;
                            $bar->advance();
                            continue;
                        }

                        $datasetId = $this->getOrCreateDataset($ragflow, $category, $chunkMethod);
                        $docId     = $ragflow->uploadDocument($datasetId, $entry->content, $entry->title ?? 'Untitled');

                        $entry->update([
                            'ragflow_dataset_id' => $datasetId,
                            'ragflow_doc_id'     => $docId,
                        ]);

                        $ingested++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('MigrateKnowledgeToRagflow: entry failed', [
                            'id'    => $entry->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("Complete: {$ingested} migrated, {$failed} failed.");

        return $failed > 0 ? 1 : 0;
    }

    private function getOrCreateDataset(RAGFlowService $ragflow, string $category, string $chunkMethod): string
    {
        if (isset($this->datasetCache[$category])) {
            return $this->datasetCache[$category];
        }

        // Check if dataset already exists
        $datasets = $ragflow->listDatasets();
        foreach ($datasets as $dataset) {
            if (($dataset['name'] ?? '') === $category) {
                $this->datasetCache[$category] = $dataset['id'];
                return $dataset['id'];
            }
        }

        // Create new dataset
        $id = $ragflow->createDataset($category, $chunkMethod);
        $this->datasetCache[$category] = $id;

        return $id;
    }
}
