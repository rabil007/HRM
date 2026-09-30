<?php

namespace App\Http\Controllers\Organization;

use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Enums\DocumentAiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\StoreDocumentAiBatchRequest;
use App\Jobs\ExtractDocumentAiBatchItemJob;
use App\Models\DocumentAiBatch;
use App\Models\Employee;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentAiBatchLifecycle;
use App\Support\EmployeeDocuments\DocumentAiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class DocumentAiBatchController extends Controller
{
    public function store(StoreDocumentAiBatchRequest $request, Employee $employee, DocumentAiSettings $settings): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 403, $request->user(), allowSelf: false);
        abort_unless($settings->isAvailableForCompany($companyId), 503, 'Document AI is temporarily unavailable.');

        $files = $request->file('files', []);
        $draftIds = $request->validated('draft_ids');
        $accepted = [];
        $rejected = [];

        foreach ($files as $index => $file) {
            if (! in_array($file->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png'], true)) {
                $rejected[] = [
                    'draft_id' => $draftIds[$index] ?? null,
                    'message' => 'Unsupported file type.',
                ];

                continue;
            }

            $accepted[] = [$file, $draftIds[$index] ?? null];
        }

        abort_if($accepted === [], 422, 'No supported files were provided.');

        $batch = DB::transaction(function () use ($accepted, $companyId, $employee, $request): DocumentAiBatch {
            $batch = DocumentAiBatch::query()->create([
                'company_id' => $companyId,
                'user_id' => $request->user()->id,
                'employee_id' => $employee->id,
                'status' => DocumentAiBatchStatus::Pending,
                'total_items' => count($accepted),
                'expires_at' => now()->addDay(),
            ]);

            foreach ($accepted as [$file, $draftId]) {
                $path = $file->storeAs(
                    "document-ai-temp/{$companyId}/{$batch->id}",
                    Str::uuid().'.'.$file->guessExtension(),
                    'local',
                );

                $batch->items()->create([
                    'client_draft_id' => $draftId,
                    'status' => DocumentAiBatchItemStatus::Queued,
                    'temporary_file_reference' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            return $batch;
        });

        activity()
            ->useLog('documents')
            ->causedBy($request->user())
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

        return response()->json([
            'batch' => $this->present($batch->fresh('items')),
            'rejected' => $rejected,
        ], 202);
    }

    public function show(Request $request, DocumentAiBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        return response()->json(['batch' => $this->present($batch->load('items'))]);
    }

    public function retry(Request $request, DocumentAiBatch $batch, int $item): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        abort_if($batch->status === DocumentAiBatchStatus::Cancelled, 409);
        abort_if($batch->expires_at->isPast(), 410);

        $target = $batch->items()->findOrFail($item);
        abort_unless($target->status === DocumentAiBatchItemStatus::Failed, 409);
        abort_if($target->attempts >= 3, 409, 'This item has reached the retry limit.');

        $target->update([
            'status' => DocumentAiBatchItemStatus::Queued,
            'safe_error_code' => null,
            'completed_at' => null,
        ]);
        DocumentAiBatchLifecycle::refresh($batch);
        ExtractDocumentAiBatchItemJob::dispatch($target->id);

        return response()->json(['batch' => $this->present($batch->fresh('items'))]);
    }

    public function cancel(Request $request, DocumentAiBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        DB::transaction(function () use ($batch): void {
            /** @var DocumentAiBatch $locked */
            $locked = DocumentAiBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [DocumentAiBatchStatus::Cancelled, DocumentAiBatchStatus::Expired], true)) {
                return;
            }

            $locked->items()
                ->whereIn('status', [
                    DocumentAiBatchItemStatus::Queued->value,
                    DocumentAiBatchItemStatus::Processing->value,
                ])
                ->update([
                    'status' => DocumentAiBatchItemStatus::Cancelled->value,
                    'completed_at' => now(),
                ]);

            $locked->update(['status' => DocumentAiBatchStatus::Cancelled]);
        });

        activity()
            ->useLog('documents')
            ->causedBy($request->user())
            ->performedOn($batch)
            ->event('document_ai_batch_cancelled')
            ->withProperties([
                'company_id' => $batch->company_id,
                'batch_id' => $batch->id,
                'employee_id' => $batch->employee_id,
            ])
            ->tap(function (Activity $activity) use ($batch): void {
                $activity->company_id = (int) $batch->company_id;
            })
            ->log('Document AI bulk extraction cancelled');

        return response()->json(['batch' => $this->present($batch->fresh('items'))]);
    }

    public function destroy(Request $request, DocumentAiBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        DocumentAiBatchLifecycle::purge($batch->load('items'));

        return response()->json(['ok' => true]);
    }

    private function authorizeBatch(Request $request, DocumentAiBatch $batch): void
    {
        abort_unless($request->user()?->can('documents.ai.use'), 403);
        abort_unless(
            $batch->company_id === (int) $request->attributes->get('current_company_id')
            && $batch->user_id === $request->user()->id,
            404,
        );

        $employee = Employee::query()
            ->where('company_id', $batch->company_id)
            ->findOrFail($batch->employee_id);

        DocumentAccess::assertEmployeeInCompany(
            $employee,
            (int) $batch->company_id,
            404,
            $request->user(),
            allowSelf: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DocumentAiBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'status' => $batch->status->value,
            'total' => $batch->total_items,
            'completed' => $batch->completed_items,
            'failed' => $batch->failed_items,
            'items' => $batch->items->map(fn ($item) => [
                'id' => $item->id,
                'draft_id' => $item->client_draft_id,
                'status' => $item->status->value,
                'filename' => $item->original_filename,
                'result' => $item->normalized_result_json,
                'error' => $item->safe_error_code ? DocumentAiErrorCode::userMessage() : null,
            ])->values(),
        ];
    }
}
