<?php

namespace App\Support\EmployeeDocuments;

use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Jobs\ExtractDocumentAiBatchItemJob;
use App\Models\DocumentAiBatch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Throwable;

final class CreateDocumentAiBatch
{
    /**
     * @param  list<array{0: UploadedFile, 1: string}>  $accepted
     * @param  list<array{draft_id: string|null, message: string}>  $rejected
     * @return array{batch: DocumentAiBatch, rejected: list<array{draft_id: string|null, message: string}>, reused: bool}
     */
    public function handle(
        int $companyId,
        User $user,
        Employee $employee,
        string $batchRequestId,
        array $accepted,
        array $rejected = [],
    ): array {
        $existing = DocumentAiBatch::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('batch_request_id', $batchRequestId)
            ->with('items')
            ->first();

        if ($existing !== null) {
            return [
                'batch' => $existing,
                'rejected' => $rejected,
                'reused' => true,
            ];
        }

        $writtenPaths = [];

        try {
            $batch = DB::transaction(function () use (
                $accepted,
                $batchRequestId,
                $companyId,
                $employee,
                $user,
                &$writtenPaths,
            ): DocumentAiBatch {
                $batch = DocumentAiBatch::query()->create([
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                    'employee_id' => $employee->id,
                    'batch_request_id' => $batchRequestId,
                    'status' => DocumentAiBatchStatus::Pending,
                    'total_items' => count($accepted),
                    'expires_at' => now()->addHours(DocumentAiBatchLifecycle::TEMP_RETENTION_HOURS),
                ]);

                foreach ($accepted as [$file, $draftId]) {
                    $path = $file->storeAs(
                        "document-ai-temp/{$companyId}/{$batch->id}",
                        Str::uuid().'.'.$file->guessExtension(),
                        'local',
                    );
                    $writtenPaths[] = $path;

                    $batch->items()->create([
                        'client_draft_id' => $draftId,
                        'status' => DocumentAiBatchItemStatus::Queued,
                        'temporary_file_reference' => $path,
                        'original_filename' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                }

                return $batch->load('items');
            });
        } catch (QueryException $e) {
            foreach ($writtenPaths as $path) {
                DocumentAiBatchLifecycle::deleteTemporaryPath($path);
            }

            $existing = DocumentAiBatch::query()
                ->where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->where('batch_request_id', $batchRequestId)
                ->with('items')
                ->first();

            if ($existing !== null) {
                return [
                    'batch' => $existing,
                    'rejected' => $rejected,
                    'reused' => true,
                ];
            }

            throw $e;
        } catch (Throwable $e) {
            foreach ($writtenPaths as $path) {
                DocumentAiBatchLifecycle::deleteTemporaryPath($path);
            }

            throw $e;
        }

        activity()
            ->useLog('documents')
            ->causedBy($user)
            ->performedOn($batch)
            ->event('document_ai_batch_started')
            ->withProperties([
                'company_id' => $companyId,
                'batch_id' => $batch->id,
                'employee_id' => $employee->id,
                'total_items' => $batch->total_items,
            ])
            ->tap(function (Activity $activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->log('Document AI bulk extraction started');

        foreach ($batch->items as $item) {
            ExtractDocumentAiBatchItemJob::dispatch($item->id);
        }

        return [
            'batch' => $batch,
            'rejected' => $rejected,
            'reused' => false,
        ];
    }
}
