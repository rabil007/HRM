<?php

namespace App\Jobs;

use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Enums\DocumentAiErrorCode;
use App\Models\DocumentAiBatchItem;
use App\Services\DocumentAiExtractionService;
use App\Support\EmployeeDocuments\DocumentAiBatchLifecycle;
use App\Support\EmployeeDocuments\DocumentAiSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class ExtractDocumentAiBatchItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(public int $itemId) {}

    public function handle(DocumentAiExtractionService $extractor, DocumentAiSettings $settings): void
    {
        $item = DocumentAiBatchItem::query()->with('batch')->find($this->itemId);
        if ($item === null || $item->batch === null) {
            return;
        }

        if (in_array($item->batch->status, [DocumentAiBatchStatus::Cancelled, DocumentAiBatchStatus::Expired], true)) {
            return;
        }

        // Worker timeout/kill can leave the item Processing without running catch().
        // Reclaim on a subsequent attempt so bounded retries actually re-run extraction.
        if (
            $item->status === DocumentAiBatchItemStatus::Processing
            && $this->attempts() > 1
        ) {
            DocumentAiBatchItem::query()
                ->whereKey($item->id)
                ->where('status', DocumentAiBatchItemStatus::Processing)
                ->update([
                    'status' => DocumentAiBatchItemStatus::Queued,
                    'safe_error_code' => null,
                ]);
            $item->refresh();
        }

        if ($item->status !== DocumentAiBatchItemStatus::Queued) {
            return;
        }

        if (! $settings->isEnabledForCompany((int) $item->batch->company_id)) {
            $this->failPermanently($item, DocumentAiErrorCode::CompanyAiDisabled);

            return;
        }

        if (! $settings->providerAvailable()) {
            if ($this->shouldRetry()) {
                throw new \RuntimeException('Document AI provider temporarily unavailable.');
            }

            $this->failPermanently($item, DocumentAiErrorCode::ProviderUnavailable);

            return;
        }

        $path = (string) $item->temporary_file_reference;
        if (! DocumentAiBatchLifecycle::isOwnedTemporaryPath($item->batch, $path)) {
            $this->failPermanently($item, DocumentAiErrorCode::TemporaryFileMissing);

            return;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            $this->failPermanently($item, DocumentAiErrorCode::TemporaryFileMissing);

            return;
        }

        $claimed = DocumentAiBatchItem::query()
            ->whereKey($item->id)
            ->where('status', DocumentAiBatchItemStatus::Queued)
            ->update([
                'status' => DocumentAiBatchItemStatus::Processing,
                'attempts' => $item->attempts + 1,
                'started_at' => now(),
                'safe_error_code' => null,
            ]);

        if ($claimed === 0) {
            return;
        }

        $item->refresh();
        $item->load('batch');

        try {
            $file = new UploadedFile(
                $disk->path($path),
                $item->original_filename,
                $item->mime_type,
                null,
                true,
            );
            $result = $extractor->extract($file)->toArray();
            $item->refresh();
            $item->batch?->refresh();

            if ($item->batch === null || $item->batch->status === DocumentAiBatchStatus::Cancelled) {
                DocumentAiBatchItem::query()
                    ->whereKey($item->id)
                    ->where('status', DocumentAiBatchItemStatus::Processing)
                    ->update([
                        'status' => DocumentAiBatchItemStatus::Cancelled,
                        'completed_at' => now(),
                    ]);

                return;
            }

            $updated = DocumentAiBatchItem::query()
                ->whereKey($item->id)
                ->where('status', DocumentAiBatchItemStatus::Processing)
                ->update([
                    'status' => DocumentAiBatchItemStatus::Completed,
                    'detected_document_type' => $result['document_type'],
                    'overall_confidence' => $result['confidence'],
                    'normalized_result_json' => $result,
                    'completed_at' => now(),
                ]);

            if ($updated === 0) {
                return;
            }

            DocumentAiBatchLifecycle::refresh($item->batch);
        } catch (InvalidArgumentException) {
            $this->failPermanently($item, DocumentAiErrorCode::InvalidOutput);
        } catch (Throwable $e) {
            $code = $this->classifyThrowable($e);
            if ($code->isRetryable() && $this->shouldRetry()) {
                DocumentAiBatchItem::query()
                    ->whereKey($item->id)
                    ->where('status', DocumentAiBatchItemStatus::Processing)
                    ->update([
                        'status' => DocumentAiBatchItemStatus::Queued,
                        'safe_error_code' => null,
                    ]);

                throw $e;
            }

            $this->failPermanently($item, $code);
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        $item = DocumentAiBatchItem::query()->with('batch')->find($this->itemId);
        if ($item === null || $item->batch === null) {
            return;
        }

        if (in_array($item->status, [DocumentAiBatchItemStatus::Completed, DocumentAiBatchItemStatus::Cancelled], true)) {
            return;
        }

        $this->failPermanently(
            $item,
            $exception === null ? DocumentAiErrorCode::ExtractionFailed : $this->classifyThrowable($exception),
        );
    }

    private function failPermanently(DocumentAiBatchItem $item, DocumentAiErrorCode $code): void
    {
        $item->refresh();
        $item->load('batch');

        if ($item->batch === null) {
            return;
        }

        if ($item->batch->status === DocumentAiBatchStatus::Cancelled) {
            DocumentAiBatchItem::query()
                ->whereKey($item->id)
                ->whereIn('status', [
                    DocumentAiBatchItemStatus::Queued->value,
                    DocumentAiBatchItemStatus::Processing->value,
                    DocumentAiBatchItemStatus::Failed->value,
                ])
                ->update([
                    'status' => DocumentAiBatchItemStatus::Cancelled,
                    'completed_at' => now(),
                ]);

            return;
        }

        DocumentAiBatchItem::query()
            ->whereKey($item->id)
            ->whereIn('status', [
                DocumentAiBatchItemStatus::Queued->value,
                DocumentAiBatchItemStatus::Processing->value,
            ])
            ->update([
                'status' => DocumentAiBatchItemStatus::Failed,
                'safe_error_code' => $code->value,
                'completed_at' => now(),
            ]);

        DocumentAiBatchLifecycle::refresh($item->batch);
    }

    private function shouldRetry(): bool
    {
        return $this->attempts() < $this->tries;
    }

    private function classifyThrowable(Throwable $e): DocumentAiErrorCode
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, '429') || str_contains($message, 'rate limit') || str_contains($message, 'too many requests')) {
            return DocumentAiErrorCode::ProviderRateLimited;
        }

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return DocumentAiErrorCode::ProviderTimeout;
        }

        if (
            str_contains($message, '503')
            || str_contains($message, '502')
            || str_contains($message, '500')
            || str_contains($message, 'unavailable')
            || str_contains($message, 'connection')
        ) {
            return DocumentAiErrorCode::ProviderUnavailable;
        }

        return DocumentAiErrorCode::ExtractionFailed;
    }
}
