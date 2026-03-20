<?php
namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\Media\MediaPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMediaAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 600;

    public function __construct(public readonly string $mediaAssetId) {}

    public function handle(MediaPipelineService $pipeline): void
    {
        $asset = MediaAsset::find($this->mediaAssetId);
        if (! $asset || $asset->status === 'ready') {
            return;
        }
        $pipeline->processAsset($this->mediaAssetId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("ProcessMediaAssetJob failed", ['asset_id' => $this->mediaAssetId, 'error' => $e->getMessage()]);
        $asset = MediaAsset::find($this->mediaAssetId);
        if ($asset) {
            $asset->update(['status' => 'failed']);
            $asset->addProcessingLog('job_failed', false, substr($e->getMessage(), 0, 500));
        }
    }
}
