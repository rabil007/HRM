<?php

namespace App\Jobs;

use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Models\DocumentAiBatchItem;
use App\Services\DocumentAiExtractionService;
use App\Support\EmployeeDocuments\DocumentAiBatchLifecycle;
use App\Support\EmployeeDocuments\DocumentAiSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractDocumentAiBatchItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    public array $backoff = [15, 60];

    public function __construct(public int $itemId) {}

    public function handle(DocumentAiExtractionService $extractor, DocumentAiSettings $settings): void
    {
        $item = DocumentAiBatchItem::query()->with('batch')->find($this->itemId);
        if (! $item || ! $item->batch || $item->status !== DocumentAiBatchItemStatus::Queued || in_array($item->batch->status, [DocumentAiBatchStatus::Cancelled, DocumentAiBatchStatus::Expired], true)) {
            return;
        }
        if (! $settings->isAvailableForCompany($item->batch->company_id)) {
            $this->failSafely($item, 'provider_unavailable');

            return;
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($item->temporary_file_reference)) {
            $this->failSafely($item, 'file_unavailable');

            return;
        }
        $item->update(['status' => DocumentAiBatchItemStatus::Processing, 'attempts' => $item->attempts + 1, 'started_at' => now(), 'safe_error_code' => null]);
        try {
            $file = new UploadedFile($disk->path($item->temporary_file_reference), $item->original_filename, $item->mime_type, null, true);
            $result = $extractor->extract($file)->toArray();
            $item->refresh();
            $item->batch->refresh();
            if ($item->batch->status === DocumentAiBatchStatus::Cancelled) {
                $item->update(['status' => DocumentAiBatchItemStatus::Cancelled, 'completed_at' => now()]);

                return;
            }
            $item->update(['status' => DocumentAiBatchItemStatus::Completed, 'detected_document_type' => $result['document_type'], 'overall_confidence' => $result['confidence'], 'normalized_result_json' => $result, 'completed_at' => now()]);
            DocumentAiBatchLifecycle::refresh($item->batch);
        } catch (Throwable) {
            $this->failSafely($item, 'extraction_failed');
        }
    }

    private function failSafely(DocumentAiBatchItem $item, string $code): void
    {
        $item->batch->refresh();
        if ($item->batch->status === DocumentAiBatchStatus::Cancelled) {
            $item->update(['status' => DocumentAiBatchItemStatus::Cancelled, 'completed_at' => now()]);

            return;
        }
        $item->update(['status' => DocumentAiBatchItemStatus::Failed, 'safe_error_code' => $code, 'completed_at' => now()]);
        DocumentAiBatchLifecycle::refresh($item->batch);
    }
}
