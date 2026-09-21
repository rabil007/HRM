<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\HistoricalCrewImportBatchStatus;
use App\Enums\HistoricalCrewImportRowStatus;
use App\Models\CrewAssignment;
use App\Models\HistoricalCrewImportBatch;
use App\Models\HistoricalCrewImportRow;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HistoricalCrewImportExecutionService
{
    public const STALE_AFTER_MINUTES = 15;

    public function __construct(
        private readonly HistoricalCrewImportPreviewService $previewService,
        private readonly HistoricalCrewAssignmentService $historicalService,
        private readonly HistoricalCrewImportBatchNumberGenerator $batchNumbers,
        private readonly HistoricalCrewImportResultExporter $resultExporter,
    ) {}

    /**
     * Revalidate and import Ready + Warning rows. Blocked rows are recorded as skipped.
     * Supports resume of stale/failed batches under the same idempotency key + workbook hash.
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

        $workbookHash = $this->hashWorkbook($file);

        $existing = HistoricalCrewImportBatch::query()
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->handleExistingBatch($existing, $companyId, $file, $actor, $workbookHash);
        }

        $evaluation = $this->previewService->evaluateWorkbook($companyId, $file, $actor);

        if ($evaluation['summary']['importable'] < 1) {
            throw ValidationException::withMessages([
                'file' => 'No Ready or Warning rows are available to import.',
            ]);
        }

        try {
            $batch = $this->createBatch($companyId, $file, $actor, $idempotencyKey, $workbookHash, $evaluation['summary']);
        } catch (UniqueConstraintViolationException) {
            $existing = HistoricalCrewImportBatch::query()
                ->where('company_id', $companyId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'Another import is using this confirmation key. Please retry.',
                ]);
            }

            return $this->handleExistingBatch($existing, $companyId, $file, $actor, $workbookHash);
        }

        return $this->processBatch($batch, $evaluation['evaluated'], $actor, resumed: false);
    }

    /**
     * @return array{batch: HistoricalCrewImportBatch, result: array<string, mixed>}
     */
    private function handleExistingBatch(
        HistoricalCrewImportBatch $batch,
        int $companyId,
        UploadedFile $file,
        User $actor,
        string $workbookHash,
    ): array {
        $resolution = $this->resolveExistingBatchUnderLock((int) $batch->id, $companyId, $workbookHash);

        if ($resolution['action'] === 'present') {
            $resolution['batch']->load(['rows', 'creator:id,name']);

            return [
                'batch' => $resolution['batch'],
                'result' => $this->presentBatch($resolution['batch']),
            ];
        }

        $evaluation = $this->previewService->evaluateWorkbook($companyId, $file, $actor);

        return $this->processBatch($resolution['batch'], $evaluation['evaluated'], $actor, resumed: true);
    }

    /**
     * Atomically re-read the batch and either return it or claim it for resume.
     *
     * @return array{action: 'present'|'resume', batch: HistoricalCrewImportBatch}
     */
    private function resolveExistingBatchUnderLock(int $batchId, int $companyId, string $workbookHash): array
    {
        return DB::transaction(function () use ($batchId, $companyId, $workbookHash): array {
            $locked = HistoricalCrewImportBatch::query()
                ->whereKey($batchId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertWorkbookMatches($locked, $workbookHash);

            if (in_array($locked->status, [
                HistoricalCrewImportBatchStatus::Completed,
                HistoricalCrewImportBatchStatus::CompletedWithErrors,
            ], true)) {
                return [
                    'action' => 'present',
                    'batch' => $locked,
                ];
            }

            if ($locked->status === HistoricalCrewImportBatchStatus::Importing) {
                if (! $this->isStale($locked)) {
                    throw ValidationException::withMessages([
                        'file' => 'This import is already in progress. Wait for it to finish, or retry after it becomes idle.',
                    ]);
                }

                $this->claimBatchForResume($locked, $companyId);

                return [
                    'action' => 'resume',
                    'batch' => $locked->fresh() ?? $locked,
                ];
            }

            if ($locked->status === HistoricalCrewImportBatchStatus::Failed) {
                $this->claimBatchForResume($locked, $companyId);

                return [
                    'action' => 'resume',
                    'batch' => $locked->fresh() ?? $locked,
                ];
            }

            return [
                'action' => 'present',
                'batch' => $locked,
            ];
        });
    }

    private function claimBatchForResume(HistoricalCrewImportBatch $batch, int $companyId): void
    {
        $batch->update([
            'status' => HistoricalCrewImportBatchStatus::Importing,
            'last_progress_at' => now(),
            'completed_at' => null,
            'summary' => array_merge($batch->summary ?? [], [
                'timezone' => CompanyTimezone::forCompanyId($companyId),
                'resumed' => true,
            ]),
        ]);
    }

    /**
     * @param  array{total: int, ready: int, warning: int, blocked: int, importable: int}  $summary
     */
    private function createBatch(
        int $companyId,
        UploadedFile $file,
        User $actor,
        string $idempotencyKey,
        string $workbookHash,
        array $summary,
    ): HistoricalCrewImportBatch {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return HistoricalCrewImportBatch::query()->create([
                    'company_id' => $companyId,
                    'batch_no' => $this->batchNumbers->next($companyId),
                    'created_by' => $actor->id,
                    'original_filename' => $file->getClientOriginalName() ?: 'historical-crew-import.xlsx',
                    'status' => HistoricalCrewImportBatchStatus::Importing,
                    'total_rows' => $summary['total'],
                    'ready_rows' => $summary['ready'],
                    'warning_rows' => $summary['warning'],
                    'blocked_rows' => $summary['blocked'],
                    'imported_rows' => 0,
                    'failed_rows' => 0,
                    'skipped_rows' => 0,
                    'idempotency_key' => $idempotencyKey,
                    'workbook_hash' => $workbookHash,
                    'started_at' => now(),
                    'last_progress_at' => now(),
                    'summary' => [
                        'timezone' => CompanyTimezone::forCompanyId($companyId),
                    ],
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->isIdempotencyCollision($exception)) {
                    throw $exception;
                }

                // batch_no collision — retry with a new number.
            }
        }

        throw ValidationException::withMessages([
            'file' => 'Unable to start the import batch. Please try again.',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $evaluatedRows
     * @return array{batch: HistoricalCrewImportBatch, result: array<string, mixed>}
     */
    private function processBatch(
        HistoricalCrewImportBatch $batch,
        array $evaluatedRows,
        User $actor,
        bool $resumed,
    ): array {
        $existingRows = HistoricalCrewImportRow::query()
            ->where('historical_crew_import_batch_id', $batch->id)
            ->get()
            ->keyBy('row_number');

        try {
            foreach ($evaluatedRows as $row) {
                $rowNumber = (int) $row['row_number'];
                $existing = $existingRows->get($rowNumber);

                if ($existing instanceof HistoricalCrewImportRow && $this->isTerminalRow($existing)) {
                    continue;
                }

                $this->processEvaluatedRow($batch, $row, $actor, $existing instanceof HistoricalCrewImportRow ? $existing : null);
                $this->touchProgress($batch);
            }

            $this->finalizeBatch($batch, $actor, $resumed);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->markBatchFailed($batch, $exception);

            throw ValidationException::withMessages([
                'file' => 'Historical import was interrupted. Already imported rows were preserved. Retry with the same workbook to continue the remaining rows.',
            ]);
        }

        $batch->refresh()->load(['rows', 'creator:id,name']);

        return [
            'batch' => $batch,
            'result' => $this->presentBatch($batch),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function processEvaluatedRow(
        HistoricalCrewImportBatch $batch,
        array $row,
        User $actor,
        ?HistoricalCrewImportRow $existing,
    ): void {
        $status = (string) $row['status'];

        if ($status === 'blocked') {
            $this->persistRowResultAtomically(
                $batch,
                $row,
                HistoricalCrewImportRowStatus::Skipped,
                null,
                $row['warnings'] ?? [],
                array_values($row['errors'] ?? []),
                null,
                $existing,
            );

            return;
        }

        /** @var HistoricalCrewAssignmentData|null $data */
        $data = $row['data'] ?? null;

        if (! $data instanceof HistoricalCrewAssignmentData) {
            $this->persistRowResultAtomically(
                $batch,
                $row,
                HistoricalCrewImportRowStatus::Failed,
                null,
                $row['warnings'] ?? [],
                ['Row could not be normalized for import.'],
                null,
                $existing,
            );

            return;
        }

        try {
            DB::transaction(function () use ($batch, $row, $actor, $data, $existing): void {
                $assignment = $this->historicalService->create(
                    data: $data,
                    actorId: $actor->id,
                    importBatchId: (int) $batch->id,
                );

                $rowStatus = ($row['warnings'] ?? []) !== []
                    ? HistoricalCrewImportRowStatus::ImportedWithWarnings
                    : HistoricalCrewImportRowStatus::Imported;

                $this->writeRowResult(
                    $batch,
                    $row,
                    $rowStatus,
                    $assignment->id,
                    $row['warnings'] ?? [],
                    [],
                    $assignment->assignment_no,
                    $existing,
                );
            });
        } catch (ValidationException $exception) {
            $message = HistoricalCrewAssignmentErrorMapper::alertMessage($exception->errors())
                ?? collect($exception->errors())->flatten()->first()
                ?? 'Validation failed during final import.';

            $this->persistRowResultAtomically(
                $batch,
                $row,
                HistoricalCrewImportRowStatus::Failed,
                null,
                $row['warnings'] ?? [],
                [(string) $message],
                null,
                $existing,
            );
        } catch (\Throwable) {
            $this->persistRowResultAtomically(
                $batch,
                $row,
                HistoricalCrewImportRowStatus::Failed,
                null,
                $row['warnings'] ?? [],
                ['Import failed during final write.'],
                null,
                $existing,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     */
    private function persistRowResultAtomically(
        HistoricalCrewImportBatch $batch,
        array $row,
        HistoricalCrewImportRowStatus $status,
        ?int $assignmentId,
        array $warnings,
        array $errors,
        ?string $assignmentNo,
        ?HistoricalCrewImportRow $existing,
    ): void {
        DB::transaction(function () use ($batch, $row, $status, $assignmentId, $warnings, $errors, $assignmentNo, $existing): void {
            $this->writeRowResult(
                $batch,
                $row,
                $status,
                $assignmentId,
                $warnings,
                $errors,
                $assignmentNo,
                $existing,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     */
    protected function writeRowResult(
        HistoricalCrewImportBatch $batch,
        array $row,
        HistoricalCrewImportRowStatus $status,
        ?int $assignmentId,
        array $warnings,
        array $errors,
        ?string $assignmentNo,
        ?HistoricalCrewImportRow $existing,
    ): void {
        $current = $existing instanceof HistoricalCrewImportRow
            ? ($existing->fresh() ?? $existing)
            : HistoricalCrewImportRow::query()
                ->where('historical_crew_import_batch_id', $batch->id)
                ->where('row_number', (int) $row['row_number'])
                ->first();

        // Never overwrite a successfully imported row with a non-success result.
        if (
            $current instanceof HistoricalCrewImportRow
            && $this->isSuccessfullyImportedRow($current)
            && ! in_array($status, [
                HistoricalCrewImportRowStatus::Imported,
                HistoricalCrewImportRowStatus::ImportedWithWarnings,
            ], true)
        ) {
            return;
        }

        $attributes = [
            'employee_no' => $row['employee_no'] ?? null,
            'employee_name' => $row['employee_name'] ?? null,
            'vessel_name' => $row['vessel_name'] ?? null,
            'rank_name' => $row['rank_name'] ?? null,
            'status' => $status,
            'crew_assignment_id' => $assignmentId,
            'assignment_no' => $assignmentNo,
            'warnings' => array_values($warnings),
            'errors' => array_values($errors),
        ];

        if ($current instanceof HistoricalCrewImportRow) {
            $current->update($attributes);

            return;
        }

        HistoricalCrewImportRow::query()->updateOrCreate(
            [
                'historical_crew_import_batch_id' => $batch->id,
                'row_number' => (int) $row['row_number'],
            ],
            $attributes,
        );
    }

    private function finalizeBatch(HistoricalCrewImportBatch $batch, User $actor, bool $resumed): void
    {
        $counts = $this->recountRows($batch);

        $finalStatus = ($counts['failed'] > 0 || $counts['skipped'] > 0)
            ? HistoricalCrewImportBatchStatus::CompletedWithErrors
            : HistoricalCrewImportBatchStatus::Completed;

        $batch->update([
            'status' => $finalStatus,
            'imported_rows' => $counts['imported'],
            'failed_rows' => $counts['failed'],
            'skipped_rows' => $counts['skipped'],
            'completed_at' => now(),
            'last_progress_at' => now(),
            'summary' => array_merge($batch->summary ?? [], [
                'imported_with_warnings' => $counts['imported_with_warnings'],
                'resumed' => $resumed || (bool) ($batch->summary['resumed'] ?? false),
            ]),
        ]);

        activity()
            ->performedOn($batch)
            ->causedBy($actor)
            ->event('historical_crew_import_completed')
            ->withProperties([
                'company_id' => (int) $batch->company_id,
                'batch_id' => $batch->id,
                'batch_no' => $batch->batch_no,
                'filename' => $batch->original_filename,
                'total_rows' => $batch->total_rows,
                'imported_rows' => $counts['imported'],
                'warning_rows' => $batch->warning_rows,
                'blocked_rows' => $batch->blocked_rows,
                'failed_rows' => $counts['failed'],
                'resumed' => $resumed,
                'actor_id' => $actor->id,
            ])
            ->tap(function ($activity) use ($batch): void {
                $activity->company_id = (int) $batch->company_id;
            })
            ->log('Historical crew import completed');
    }

    private function markBatchFailed(HistoricalCrewImportBatch $batch, \Throwable $exception): void
    {
        $counts = $this->recountRows($batch);

        $batch->update([
            'status' => HistoricalCrewImportBatchStatus::Failed,
            'imported_rows' => $counts['imported'],
            'failed_rows' => $counts['failed'],
            'skipped_rows' => $counts['skipped'],
            'last_progress_at' => now(),
            'summary' => array_merge($batch->summary ?? [], [
                'imported_with_warnings' => $counts['imported_with_warnings'],
                'system_error' => class_basename($exception),
            ]),
        ]);
    }

    /**
     * @return array{imported: int, failed: int, skipped: int, imported_with_warnings: int}
     */
    private function recountRows(HistoricalCrewImportBatch $batch): array
    {
        $rows = HistoricalCrewImportRow::query()
            ->where('historical_crew_import_batch_id', $batch->id)
            ->get(['status']);

        $imported = 0;
        $failed = 0;
        $skipped = 0;
        $importedWithWarnings = 0;

        foreach ($rows as $row) {
            if ($row->status === HistoricalCrewImportRowStatus::Imported) {
                $imported++;
            } elseif ($row->status === HistoricalCrewImportRowStatus::ImportedWithWarnings) {
                $imported++;
                $importedWithWarnings++;
            } elseif ($row->status === HistoricalCrewImportRowStatus::Failed) {
                $failed++;
            } elseif ($row->status === HistoricalCrewImportRowStatus::Skipped) {
                $skipped++;
            }
        }

        return [
            'imported' => $imported,
            'failed' => $failed,
            'skipped' => $skipped,
            'imported_with_warnings' => $importedWithWarnings,
        ];
    }

    /**
     * Successfully imported rows with a live assignment are terminal.
     * Domain-blocked Skipped rows stay terminal for the same workbook.
     * Failed rows are retryable on resume.
     * Imported markers without a live assignment are re-evaluated.
     */
    private function isTerminalRow(HistoricalCrewImportRow $row): bool
    {
        if (in_array($row->status, [
            HistoricalCrewImportRowStatus::Imported,
            HistoricalCrewImportRowStatus::ImportedWithWarnings,
        ], true)) {
            return $this->isSuccessfullyImportedRow($row);
        }

        return $row->status === HistoricalCrewImportRowStatus::Skipped;
    }

    private function isSuccessfullyImportedRow(HistoricalCrewImportRow $row): bool
    {
        if (! in_array($row->status, [
            HistoricalCrewImportRowStatus::Imported,
            HistoricalCrewImportRowStatus::ImportedWithWarnings,
        ], true)) {
            return false;
        }

        if ($row->crew_assignment_id === null) {
            return false;
        }

        return CrewAssignment::query()->whereKey($row->crew_assignment_id)->exists();
    }

    private function isStale(HistoricalCrewImportBatch $batch): bool
    {
        $progressAt = $batch->last_progress_at ?? $batch->updated_at ?? $batch->started_at;

        if ($progressAt === null) {
            return true;
        }

        return $progressAt->lte(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    private function touchProgress(HistoricalCrewImportBatch $batch): void
    {
        $batch->forceFill(['last_progress_at' => now()])->saveQuietly();
    }

    private function hashWorkbook(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded spreadsheet could not be read.',
            ]);
        }

        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded spreadsheet could not be hashed.',
            ]);
        }

        return $hash;
    }

    private function assertWorkbookMatches(HistoricalCrewImportBatch $batch, string $workbookHash): void
    {
        if ($batch->workbook_hash === null || $batch->workbook_hash === '') {
            $batch->forceFill(['workbook_hash' => $workbookHash])->saveQuietly();

            return;
        }

        if (! hash_equals((string) $batch->workbook_hash, $workbookHash)) {
            throw ValidationException::withMessages([
                'file' => 'This confirmation key belongs to a different workbook. Re-validate the original file or start a new import.',
            ]);
        }
    }

    private function isIdempotencyCollision(UniqueConstraintViolationException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'idempotency_key')
            || str_contains($message, 'uq_hist_crew_batches_company_idem')
            || str_contains($message, 'company_id_idempotency_key');
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
            'resumed' => (bool) ($batch->summary['resumed'] ?? false),
            'created_by' => $batch->creator?->name,
            'started_at' => $batch->started_at?->toIso8601String(),
            'last_progress_at' => $batch->last_progress_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'created_at' => $batch->created_at?->toIso8601String(),
        ];
    }
}
