<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
class MediaAsset extends Model {
    use HasUuids, SoftDeletes;
    protected $fillable = [
        'workflow_id','original_name','storage_key','storage_bucket','mime_type','extension',
        'file_size_bytes','status','virus_clean','clamav_result','metadata','extracted_text',
        'content_category','processing_log','processed_key','thumbnail_key',
        'uploaded_by_user_id','uploaded_via_chat_id','scanned_at','processed_at'
    ];
    protected $casts = [
        'metadata'=>'array','processing_log'=>'array','virus_clean'=>'boolean',
        'scanned_at'=>'datetime','processed_at'=>'datetime'
    ];
    public function isVideo(): bool { return str_starts_with($this->mime_type, 'video/'); }
    public function isImage(): bool { return str_starts_with($this->mime_type, 'image/'); }
    public function isPdf(): bool { return $this->mime_type === 'application/pdf'; }
    public function addProcessingLog(string $step, bool $success, string $message): void {
        $log = $this->processing_log ?? [];
        $log[] = ['step'=>$step,'success'=>$success,'message'=>$message,'at'=>now()->toIso8601String()];
        $this->update(['processing_log'=>$log]);
    }
}
