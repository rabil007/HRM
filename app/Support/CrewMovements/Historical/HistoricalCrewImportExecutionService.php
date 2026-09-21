<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\HistoricalCrewImportBatchStatus;
use App\Enums\HistoricalCrewImportRowStatus;
use App\Models\HistoricalCrewImportBatch;
use App\Models\HistoricalCrewImportRow;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class HistoricalCrewImportExecutionService
{
    public function __construct(
        private readonly HistoricalCrewImportPreviewService $previewService,
        private readonly HistoricalCrewAssignmentService $historicalService,
        private readonly HistoricalCrewImportBatchNumberGenerator $batchNumbers,
        private readonly HistoricalCrewImportResultExporter $resultExporter,
    ) {}

    /**
     * Revalidate and import Ready + Warning rows. Blocked rows are recorded as skipped.
     *
     * @return array{batch: HistoricalCrewImportBatch, result: array<string, mixed>}
     */
    public function import(int $companyId, UploadedFile $file, User $actor, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid import confirmation key is required.',
            ]);
        }

        $existing = HistoricalCrewImportBatch::query()
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return [
                'batch' => $existing->load(['rows', 'creator:id,name']),
                'result' => $this->presentBatch($existing),
            ];
        }

        $evaluation = $this->previewService->evaluateWorkbook($companyId, $file, $actor);
        $importable = $evaluation['summary']['importable'];

        if ($importable < 1) {
            throw ValidationException::withMessages([
                'file' => 'No Ready or Warning rows are available to import.',
            ]);
        }

        $batch = HistoricalCrewImportBatch::query()->create([
            'company_id' => $companyId,
            'batch_no' => $this->batchNumbers->next($companyId),
            'created_by' => $actor->id,
            'original_filename' => $file->getClientOriginalName() ?: 'historical-crew-import.xlsx',
            'status' => HistoricalCrewImportBatchStatus::Importing,
            'total_rows' => $evaluation['summary']['total'],
            'ready_rows' => $evaluation['summary']['ready'],
            'warning_rows' => $evaluation['summary']['warning'],
            'blocked_rows' => $evaluation['summary']['blocked'],
            'imported_rows' => 0,
            'failed_rows' => 0,
            'skipped_rows' => 0,
            'idempotency_key' => $idempotencyKey,
            'started_at' => now(),
            'summary' => [
                'timezone' => CompanyTimezone::forCompanyId($companyId),
            ],
        ]);

        $imported = 0;
        $failed = 0;
        $skipped = 0;
        $importedWithWarnings = 0;

        foreach ($evaluation['evaluated'] as $row) {
            $status = (string) $row['status'];

            if ($status === 'blocked') {
                $skipped++;
                $this->persistRowResult(
                    $batch,
                    $row,
                    HistoricalCrewImportRowStatus::Skipped,
                    null,
                    $row['warnings'],
                    array_values($row['errors']),
                );

                continue;
            }

            /** @var HistoricalCrewAssignmentData|null $data */
            $data = $row['data'] ?? null;

            if (! $data instanceof HistoricalCrewAssignmentData) {
                $failed++;
                $this->persistRowResult(
                    $batch,
                    $row,
                    HistoricalCrewImportRowStatus::Failed,
                    null,
                    $row['warnings'],
                    ['Row could not be normalized for import.'],
                );

                continue;
            }

            try {
                $assignment = $this->historicalService->create(
                    data: $data,
                    actorId: $actor->id,
                    importBatchId: (int) $batch->id,
                );

                $imported++;
                $rowStatus = ($row['warnings'] ?? []) !== []
                    ? HistoricalCrewImportRowStatus::ImportedWithWarnings
                    : HistoricalCrewImportRowStatus::Imported;

                if ($rowStatus === HistoricalCrewImportRowStatus::ImportedWithWarnings) {
                    $importedWithWarnings++;
                }

                $this->persistRowResult(
                    $batch,
                    $row,
                    $rowStatus,
                    $assignment->id,
                    $row['warnings'],
                    [],
                    $assignment->assignment_no,
                );
            } catch (\Throwable $exception) {
                $failed++;
                $message = $exception instanceof ValidationException
                    ? (HistoricalCrewAssignmentErrorMapper::alertMessage($exception->errors())
                        ?? collect($exception->errors())->flatten()->first()
                        ?? 'Validation failed during final import.')
                    : 'Import failed during final write.';

                $this->persistRowResult(
                    $batch,
                    $row,
                    HistoricalCrewImportRowStatus::Failed,
                    null,
                    $row['warnings'],
                    [(string) $message],
                );
            }
        }

        $finalStatus = $failed > 0 || $skipped > 0
            ? HistoricalCrewImportBatchStatus::CompletedWithErrors
            : HistoricalCrewImportBatchStatus::Completed;

        $batch->update([
            'status' => $finalStatus,
            'imported_rows' => $imported,
            'failed_rows' => $failed,
            'skipped_rows' => $skipped,
            'completed_at' => now(),
            'summary' => array_merge($batch->summary ?? [], [
                'imported_with_warnings' => $importedWithWarnings,
            ]),
        ]);

        activity()
            ->performedOn($batch)
            ->causedBy($actor)
            ->event('historical_crew_import_completed')
            ->withProperties([
                'company_id' => $companyId,
                'batch_id' => $batch->id,
                'batch_no' => $batch->batch_no,
                'filename' => $batch->original_filename,
                'total_rows' => $batch->total_rows,
                'imported_rows' => $imported,
                'warning_rows' => $batch->warning_rows,
                'blocked_rows' => $batch->blocked_rows,
                'failed_rows' => $failed,
                'actor_id' => $actor->id,
            ])
            ->tap(function ($activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->log('Historical crew import completed');

        $batch->refresh()->load(['rows', 'creator:id,name']);

        return [
            'batch' => $batch,
            'result' => $this->presentBatch($batch),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentBatches(int $companyId, int $limit = 8): array
    {
        return HistoricalCrewImportBatch::query()
            ->where('company_id', $companyId)
            ->with('creator:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (HistoricalCrewImportBatch $batch): array => $this->presentBatchSummary($batch))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function batchDetail(int $companyId, int $batchId): array
    {
        $batch = HistoricalCrewImportBatch::query()
            ->where('company_id', $companyId)
            ->with(['rows', 'creator:id,name'])
            ->findOrFail($batchId);

        return $this->presentBatch($batch);
    }

    /**
     * @return array{path: string, filename: string}
     */
    public function exportResultWorkbook(int $companyId, int $batchId): array
    {
        $batch = HistoricalCrewImportBatch::query()
            ->where('company_id', $companyId)
            ->with('rows')
            ->findOrFail($batchId);

        return $this->resultExporter->export($batch);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     */
    private function persistRowResult(
        HistoricalCrewImportBatch $batch,
        array $row,
        HistoricalCrewImportRowStatus $status,
        ?int $assignmentId,
        array $warnings,
        array $errors,
        ?string $assignmentNo = null,
    ): void {
        HistoricalCrewImportRow::query()->create([
            'historical_crew_import_batch_id' => $batch->id,
            'row_number' => (int) $row['row_number'],
            'employee_no' => $row['employee_no'] ?? null,
            'employee_name' => $row['employee_name'] ?? null,
            'vessel_name' => $row['vessel_name'] ?? null,
            'rank_name' => $row['rank_name'] ?? null,
            'status' => $status,
            'crew_assignment_id' => $assignmentId,
            'assignment_no' => $assignmentNo,
            'warnings' => array_values($warnings),
            'errors' => array_values($errors),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentBatch(HistoricalCrewImportBatch $batch): array
    {
        $rows = $batch->relationLoaded('rows')
            ? $batch->rows
            : $batch->rows()->orderBy('row_number')->get();

        return [
            ...$this->presentBatchSummary($batch),
            'rows' => $rows
                ->sortBy('row_number')
                ->values()
                ->map(fn (HistoricalCrewImportRow $row): array => [
                    'row' => $row->row_number,
                    'employee_no' => $row->employee_no,
                    'employee_name' => $row->employee_name,
                    'vessel' => $row->vessel_name,
                    'rank' => $row->rank_name,
                    'status' => $row->status->value,
                    'status_label' => $row->status->label(),
                    'assignment_no' => $row->assignment_no,
                    'crew_assignment_id' => $row->crew_assignment_id,
                    'warnings' => $row->warnings ?? [],
                    'errors' => $row->errors ?? [],
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBatchSummary(HistoricalCrewImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'batch_no' => $batch->batch_no,
            'original_filename' => $batch->original_filename,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'total_rows' => $batch->total_rows,
            'ready_rows' => $batch->ready_rows,
            'warning_rows' => $batch->warning_rows,
            'blocked_rows' => $batch->blocked_rows,
            'imported_rows' => $batch->imported_rows,
            'failed_rows' => $batch->failed_rows,
            'skipped_rows' => $batch->skipped_rows,
            'imported_with_warnings' => (int) (($batch->summary['imported_with_warnings'] ?? 0)),
            'created_by' => $batch->creator?->name,
            'started_at' => $batch->started_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'created_at' => $batch->created_at?->toIso8601String(),
        ];
    }
}
