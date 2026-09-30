<?php

namespace App\Models;

use App\Enums\DocumentAiBatchItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAiBatchItem extends Model
{
    protected $fillable = ['document_ai_batch_id', 'client_draft_id', 'status', 'temporary_file_reference', 'original_filename', 'mime_type', 'file_size', 'detected_document_type', 'overall_confidence', 'normalized_result_json', 'safe_error_code', 'attempts', 'started_at', 'completed_at'];

    protected $hidden = ['temporary_file_reference'];

    protected function casts(): array
    {
        return ['status' => DocumentAiBatchItemStatus::class, 'normalized_result_json' => 'array', 'overall_confidence' => 'float', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DocumentAiBatch::class, 'document_ai_batch_id');
    }
}
