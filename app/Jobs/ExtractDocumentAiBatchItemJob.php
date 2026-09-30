<?php

namespace App\Jobs;

use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Enums\DocumentAiErrorCode;
use App\Exceptions\DocumentAiProviderException;
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

    /** Bounded queue attempts; must stay below database queue retry_after (default 660s). */
    public int $tries = 3;

    /** Must exceed DocumentAiProviderExtractor::timeout() (30) and stay below retry_after. */
    public int $timeout = 45;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public const MAX_ITEM_ATTEMPTS = 3;

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

        if ($item->attempts >= self::MAX_ITEM_ATTEMPTS) {
            $this->failPermanently($item, DocumentAiErrorCode::ExtractionFailed);

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

        if (! $settings->isEnabledForCompany((int) $item->batch->company_id)) {
            $this->failPermanently($item, DocumentAiErrorCode::CompanyAiDisabled);

            return;
        }

        if (! $settings->providerAvailable()) {
            $this->releaseForRetryOrFail($item, DocumentAiErrorCode::ProviderUnavailable);

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
        } catch (Throwable $e) {
            $code = $this->classifyThrowable($e);
            if ($code->isRetryable()) {
                $this->releaseForRetryOrFail($item, $code, $e);

                return;
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

    private function releaseForRetryOrFail(
        DocumentAiBatchItem $item,
        DocumentAiErrorCode $code,
        ?Throwable $exception = null,
    ): void {
        if ($this->shouldRetry() && $item->attempts < self::MAX_ITEM_ATTEMPTS) {
            DocumentAiBatchItem::query()
                ->whereKey($item->id)
                ->where('status', DocumentAiBatchItemStatus::Processing)
                ->update([
                    'status' => DocumentAiBatchItemStatus::Queued,
                    'safe_error_code' => null,
                ]);

            throw $exception ?? new DocumentAiProviderException($code);
        }

        $this->failPermanently($item, $code);
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
        return DocumentAiProviderException::classify($e);
    }
}
