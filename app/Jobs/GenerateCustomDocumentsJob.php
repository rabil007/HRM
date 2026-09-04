<?php

namespace App\Jobs;

use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\Employee;
use App\Services\Documents\CustomTemplatePdfRenderer;
use App\Support\BulkDocuments\DocumentGenerationItemErrorPresenter;
use App\Support\Documents\Actions\SyncGeneratedEmployeeDocument;
use App\Support\Documents\DocumentInstanceStorage;
use App\Support\Documents\Exceptions\DocumentTemplateLayoutException;
use App\Support\Documents\Exceptions\DocumentTemplateSourceUnavailableException;
use App\Support\Documents\Lifecycle\Actions\CreateDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\StartDocumentLifecycleAutomation;
use App\Support\Documents\NotifyDocumentGenerationFinished;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class GenerateCustomDocumentsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const CHUNK_SIZE = 10;

    /**
     * Overlay generation runs FPDI parse, Chromium measurement, overlay render,
     * composition, private storage, and a DB transaction per employee. Shared
     * hosting with a 120s timeout cannot reliably finish 10 overlay employees
     * in one job, so overlay chunks stay smaller while content keeps CHUNK_SIZE.
     */
    public const PDF_OVERLAY_CHUNK_SIZE = 4;

    /** Minimum length covering the `%PDF-1.` magic used by real files and test stubs. */
    public const MIN_GENERATED_PDF_BYTES = 8;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $companyId,
        public int $userId,
        public int $runId,
        public bool $allowRepeatGeneration = false,
        public ?int $afterRunItemId = null,
        public int $cumulativeGenerated = 0,
        public int $cumulativeSkipped = 0,
        public int $cumulativeFailed = 0,
    ) {}

    public function handle(
        CustomTemplatePdfRenderer $renderer,
        SyncGeneratedEmployeeDocument $syncEmployeeDoc,
    ): void {
        if ($this->afterRunItemId === null) {
            DocumentGenerationRun::query()
                ->whereKey($this->runId)
                ->where('company_id', $this->companyId)
                ->where('status', 'queued')
                ->update([
                    'status' => 'running',
                    'started_at' => now(),
                ]);
        }

        /** @var DocumentGenerationRun|null $run */
        $run = DocumentGenerationRun::query()
            ->forCompany($this->companyId)
            ->with(['template', 'templateVersion'])
            ->find($this->runId);

        if ($run === null) {
            return;
        }

        if (in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        $template = $run->template;
        $version = $run->templateVersion;

        // Allow execution if version was Published at run creation, or is now Archived (historical immutable).
        // Never allow Draft version.
        if (
            $template === null
            || $version === null
            || (! $version->isPublished() && ! $version->isArchived())
            || $template->company_id !== $this->companyId
            || $version->company_id !== $this->companyId
            || (int) $version->document_generation_template_id !== (int) $template->id
        ) {
            $this->finishRun($run, 'failed');

            return;
        }

        $this->failClaimedProcessingItems($run);

        $lastProcessedItemId = $this->afterRunItemId;

        $items = DocumentGenerationRunItem::query()
            ->where('company_id', $this->companyId)
            ->where('document_generation_run_id', $run->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($this->chunkSize($template))
            ->get();

        foreach ($items as $item) {
            $lastProcessedItemId = $item->id;

            // Atomic row claim: only process if status is currently 'pending'
            $claimed = DocumentGenerationRunItem::query()
                ->where('id', $item->id)
                ->where('company_id', $this->companyId)
                ->where('status', 'pending')
                ->update(['status' => 'processing', 'updated_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $tempPdfPath = null;
            $canonicalPath = null;
            $libraryFilePath = null;

            try {
                /** @var Employee|null $employee */
                $employee = Employee::query()
                    ->where('company_id', $this->companyId)
                    ->find($item->employee_id);

                if ($employee === null) {
                    $item->update([
                        'status' => 'failed',
                        'error_code' => 'EMPLOYEE_NOT_FOUND',
                        'error_message' => DocumentGenerationItemErrorPresenter::userMessage('EMPLOYEE_NOT_FOUND'),
                    ]);

                    continue;
                }

                // Check if a library PDF still exists for this exact template version
                $hasExistingInstance = DocumentInstance::query()
                    ->forCompany($this->companyId)
                    ->where('employee_id', $employee->id)
                    ->where('document_generation_template_version_id', $version->id)
                    ->withLibraryDocument()
                    ->exists();

                if (! $this->allowRepeatGeneration && $hasExistingInstance) {
                    $item->update(['status' => 'skipped']);

                    continue;
                }

                // 1. Render temp PDF
                $pdfBytes = $renderer->render($template, $version, $employee, $this->companyId);
                $this->assertValidGeneratedPdf($pdfBytes, $run, $item);

                $tempPdfPath = tempnam(sys_get_temp_dir(), 'custom_gen_');
                if ($tempPdfPath === false) {
                    throw new \RuntimeException('Failed to allocate temporary render file.');
                }

                file_put_contents($tempPdfPath, $pdfBytes);

                // 2. Store canonical artifact in private/document-instances/
                $artifact = DocumentInstanceStorage::storePdf($tempPdfPath, $this->companyId);
                $canonicalPath = $artifact['path'];

                // 3. Store library representation in private/employee-documents/
                // Note: File path is assigned to $libraryFilePath BEFORE entering DB transaction
                $storedLibraryDoc = $syncEmployeeDoc->storeLibraryFile(
                    $employee,
                    $template,
                    $version,
                    $tempPdfPath,
                    $this->companyId,
                );
                $libraryFilePath = $storedLibraryDoc->filePath;

                // 4. Transactional database creation: ALL database writes inside one atomic transaction!
                // Lifecycle registration (when configured) commits with the instance so manual
                // workflow/signing cannot race ahead of the automatic lifecycle guard.
                /** @var array{status: 'created', instance: DocumentInstance, instanceVersion: DocumentInstanceVersion, lifecycleId: int|null}|array{status: 'skipped'} $creationResult */
                $creationResult = DB::transaction(function () use (
                    $employee,
                    $template,
                    $version,
                    $run,
                    $item,
                    $artifact,
                    $storedLibraryDoc,
                    $syncEmployeeDoc,
                ): array {
                    // When non-repeat generation, lock the employee row and re-check existence
                    // to prevent concurrent cross-run duplicate generation
                    if (! $this->allowRepeatGeneration) {
                        Employee::query()
                            ->where('company_id', $this->companyId)
                            ->where('id', $employee->id)
                            ->lockForUpdate()
                            ->first();

                        $alreadyGenerated = DocumentInstance::query()
                            ->forCompany($this->companyId)
                            ->where('employee_id', $employee->id)
                            ->where('document_generation_template_version_id', $version->id)
                            ->withLibraryDocument()
                            ->exists();

                        if ($alreadyGenerated) {
                            $item->update(['status' => 'skipped']);

                            return ['status' => 'skipped'];
                        }
                    }

                    // Create EmployeeDocument inside the transaction
                    $employeeDocument = $syncEmployeeDoc->createEmployeeDocumentRecord(
                        $storedLibraryDoc,
                        $employee,
                        $this->companyId,
                        $this->userId,
                    );

                    /** @var DocumentInstance $instance */
                    $instance = DocumentInstance::query()->create([
                        'company_id' => $this->companyId,
                        'employee_id' => $employee->id,
                        'employee_name_snapshot' => (string) $employee->name,
                        'employee_no_snapshot' => $employee->employee_no,
                        'document_generation_template_id' => $template->id,
                        'document_generation_template_version_id' => $version->id,
                        'document_type_id' => $template->document_type_id,
                        'document_generation_run_id' => $run->id,
                        'employee_document_id' => $employeeDocument->id,
                        'template_name_snapshot' => $template->name,
                        'template_version_number' => $version->version,
                        'title_snapshot' => $template->name,
                        'status' => 'generated',
                        'generated_by' => $this->userId,
                        'generated_at' => now(),
                    ]);

                    /** @var DocumentInstanceVersion $instanceVersion */
                    $instanceVersion = DocumentInstanceVersion::query()->create([
                        'company_id' => $this->companyId,
                        'document_instance_id' => $instance->id,
                        'version' => 1,
                        'stage' => 'generated',
                        'file_path' => $artifact['path'],
                        'original_filename' => Str::slug($template->name).'.pdf',
                        'mime_type' => 'application/pdf',
                        'size_bytes' => $artifact['size_bytes'],
                        'checksum' => $artifact['checksum'],
                        'created_by' => $this->userId,
                    ]);

                    $instance->current_version_id = $instanceVersion->id;
                    $instance->save();

                    $item->update([
                        'status' => 'completed',
                        'document_instance_id' => $instance->id,
                    ]);

                    // Privacy-safe audit log
                    $companyId = $this->companyId;
                    activity('document_generation')
                        ->performedOn($instance)
                        ->causedBy($this->userId)
                        ->tap(function (Activity $activity) use ($companyId): void {
                            $activity->company_id = $companyId;
                        })
                        ->withProperties([
                            'action' => 'document_instance_generated',
                            'company_id' => $companyId,
                            'employee_id' => $employee->id,
                            'template_id' => $template->id,
                            'template_version_id' => $version->id,
                            'document_instance_id' => $instance->id,
                            'employee_document_id' => $employeeDocument->id,
                            'generation_run_id' => $run->id,
                            'checksum' => $artifact['checksum'],
                        ])
                        ->log("Generated document '{$instance->title_snapshot}' for {$instance->employee_name_snapshot}");

                    $lifecycle = app(CreateDocumentLifecycleAutomation::class)->handle(
                        $instance,
                        $instanceVersion,
                        $version,
                        $this->userId,
                    );

                    return [
                        'status' => 'created',
                        'instance' => $instance,
                        'instanceVersion' => $instanceVersion,
                        'lifecycleId' => $lifecycle?->id !== null ? (int) $lifecycle->id : null,
                    ];
                });

                if ($creationResult['status'] === 'created') {
                    // Downstream start runs after generation+registration commit.
                    // Start failures must not undo generation or delete PDFs.
                    if ($creationResult['lifecycleId'] !== null) {
                        try {
                            app(StartDocumentLifecycleAutomation::class)->handle(
                                $creationResult['lifecycleId'],
                                $this->companyId,
                            );
                        } catch (Throwable $lifecycleException) {
                            report($lifecycleException);
                        }
                    }
                } elseif ($creationResult['status'] === 'skipped') {
                    // Stale worker dedupe: clean up this worker's newly rendered files
                    if ($canonicalPath !== null) {
                        DocumentInstanceStorage::deletePdf($canonicalPath, $this->companyId);
                    }

                    if ($libraryFilePath !== null) {
                        EmployeePrivateFile::deleteStored($libraryFilePath, $this->companyId, EmployeePrivateFileKind::Document);
                    }
                }
            } catch (DocumentTemplateLayoutException $e) {
                // File compensation: clean up any partially created files
                if ($canonicalPath !== null) {
                    DocumentInstanceStorage::deletePdf($canonicalPath, $this->companyId);
                }

                if ($libraryFilePath !== null) {
                    EmployeePrivateFile::deleteStored($libraryFilePath, $this->companyId, EmployeePrivateFileKind::Document);
                }

                $item->update([
                    'status' => 'failed',
                    'error_code' => 'TEMPLATE_LAYOUT_OVERFLOW',
                    'error_message' => DocumentGenerationItemErrorPresenter::layoutOverflowMessage($e),
                ]);

                $this->logItemFailure($run, $item, 'TEMPLATE_LAYOUT_OVERFLOW', 'warning', 'PDF overlay layout overflow during generation', [
                    'placement_id' => $e->placementId,
                    'field_key' => $e->fieldKey,
                    'page' => $e->pageNumber,
                ]);
            } catch (DocumentTemplateSourceUnavailableException) {
                if ($canonicalPath !== null) {
                    DocumentInstanceStorage::deletePdf($canonicalPath, $this->companyId);
                }

                if ($libraryFilePath !== null) {
                    EmployeePrivateFile::deleteStored($libraryFilePath, $this->companyId, EmployeePrivateFileKind::Document);
                }

                $item->update([
                    'status' => 'failed',
                    'error_code' => 'TEMPLATE_SOURCE_UNAVAILABLE',
                    'error_message' => DocumentGenerationItemErrorPresenter::userMessage('TEMPLATE_SOURCE_UNAVAILABLE'),
                ]);

                $this->logItemFailure($run, $item, 'TEMPLATE_SOURCE_UNAVAILABLE', 'warning', 'PDF overlay source unavailable during generation');
            } catch (Throwable $e) {
                // File compensation: clean up newly created files if DB or storage failed
                if ($canonicalPath !== null) {
                    DocumentInstanceStorage::deletePdf($canonicalPath, $this->companyId);
                }

                if ($libraryFilePath !== null) {
                    EmployeePrivateFile::deleteStored($libraryFilePath, $this->companyId, EmployeePrivateFileKind::Document);
                }

                $item->update([
                    'status' => 'failed',
                    'error_code' => 'GENERATION_FAILED',
                    'error_message' => DocumentGenerationItemErrorPresenter::userMessage('GENERATION_FAILED'),
                ]);

                $this->logItemFailure($run, $item, 'GENERATION_FAILED', 'error', 'Custom document generation failed for run item', [
                    'error' => $e->getMessage(),
                ]);
                report($e);
            } finally {
                if ($tempPdfPath !== null && file_exists($tempPdfPath)) {
                    @unlink($tempPdfPath);
                }
            }
        }

        $counts = $this->itemStatusCounts($run);

        $totalGenerated = $counts['generated'];
        $totalSkipped = $counts['skipped'];
        $totalFailed = $counts['failed'];
        $pending = $counts['pending'];
        $processing = $counts['processing'];

        $run->update([
            'generated_count' => $totalGenerated,
            'skipped_count' => $totalSkipped,
            'failed_count' => $totalFailed,
        ]);

        if ($pending > 0) {
            self::dispatch(
                $this->companyId,
                $this->userId,
                $this->runId,
                $this->allowRepeatGeneration,
                $lastProcessedItemId,
                $totalGenerated,
                $totalSkipped,
                $totalFailed,
            );

            return;
        }

        if ($processing > 0) {
            $this->failClaimedProcessingItems($run);
            $counts = $this->itemStatusCounts($run);
            $totalGenerated = $counts['generated'];
            $totalSkipped = $counts['skipped'];
            $totalFailed = $counts['failed'];
            $pending = $counts['pending'];

            if ($pending > 0) {
                self::dispatch(
                    $this->companyId,
                    $this->userId,
                    $this->runId,
                    $this->allowRepeatGeneration,
                    null,
                    $totalGenerated,
                    $totalSkipped,
                    $totalFailed,
                );

                return;
            }
        }

        $this->finishRun(
            $run,
            $totalFailed > 0 && $totalGenerated === 0 ? 'failed' : 'completed',
            $totalGenerated,
            $totalSkipped,
            $totalFailed,
        );
    }

    public function failed(Throwable $exception): void
    {
        $run = DocumentGenerationRun::query()
            ->forCompany($this->companyId)
            ->find($this->runId);

        if ($run === null) {
            report($exception);

            return;
        }

        $this->failClaimedProcessingItems($run);
        $run->refresh();

        if (in_array($run->status, ['completed', 'failed'], true)) {
            report($exception);

            return;
        }

        $counts = $this->itemStatusCounts($run);

        $run->update([
            'generated_count' => $counts['generated'],
            'skipped_count' => $counts['skipped'],
            'failed_count' => $counts['failed'],
        ]);

        if ($counts['pending'] > 0) {
            self::dispatch(
                $this->companyId,
                $this->userId,
                $this->runId,
                $this->allowRepeatGeneration,
                null,
                $counts['generated'],
                $counts['skipped'],
                $counts['failed'],
            );

            report($exception);

            return;
        }

        $this->finishRun(
            $run,
            $counts['failed'] > 0 && $counts['generated'] === 0 ? 'failed' : 'completed',
            $counts['generated'],
            $counts['skipped'],
            $counts['failed'],
        );

        report($exception);
    }

    private function finishRun(
        DocumentGenerationRun $run,
        string $status,
        ?int $generatedCount = null,
        ?int $skippedCount = null,
        ?int $failedCount = null,
    ): void {
        $payload = [
            'status' => $status,
            'finished_at' => now(),
        ];

        if ($generatedCount !== null) {
            $payload['generated_count'] = $generatedCount;
        }

        if ($skippedCount !== null) {
            $payload['skipped_count'] = $skippedCount;
        }

        if ($failedCount !== null) {
            $payload['failed_count'] = $failedCount;
        }

        $transitioned = DocumentGenerationRun::query()
            ->whereKey($run->id)
            ->where('company_id', $this->companyId)
            ->whereIn('status', ['queued', 'running'])
            ->update($payload) === 1;

        if (! $transitioned) {
            return;
        }

        $run->refresh();

        app(NotifyDocumentGenerationFinished::class)->handle($run);
    }

    private function chunkSize(DocumentGenerationTemplate $template): int
    {
        return $template->isPdfOverlay() ? self::PDF_OVERLAY_CHUNK_SIZE : self::CHUNK_SIZE;
    }

    /**
     * @return array{generated: int, skipped: int, failed: int, pending: int, processing: int}
     */
    private function itemStatusCounts(DocumentGenerationRun $run): array
    {
        $counts = DocumentGenerationRunItem::query()
            ->where('company_id', $this->companyId)
            ->where('document_generation_run_id', $run->id)
            ->selectRaw('
                COUNT(CASE WHEN status = ? THEN 1 END) as `generated`,
                COUNT(CASE WHEN status = ? THEN 1 END) as `skipped`,
                COUNT(CASE WHEN status = ? THEN 1 END) as `failed`,
                COUNT(CASE WHEN status = ? THEN 1 END) as `pending`,
                COUNT(CASE WHEN status = ? THEN 1 END) as `processing`
            ', ['completed', 'skipped', 'failed', 'pending', 'processing'])
            ->first();

        return [
            'generated' => (int) ($counts->generated ?? 0),
            'skipped' => (int) ($counts->skipped ?? 0),
            'failed' => (int) ($counts->failed ?? 0),
            'pending' => (int) ($counts->pending ?? 0),
            'processing' => (int) ($counts->processing ?? 0),
        ];
    }

    private function failClaimedProcessingItems(DocumentGenerationRun $run): void
    {
        DocumentGenerationRunItem::query()
            ->where('company_id', $this->companyId)
            ->where('document_generation_run_id', $run->id)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'error_code' => 'JOB_FAILED',
                'error_message' => DocumentGenerationItemErrorPresenter::JOB_FAILED_MESSAGE,
                'updated_at' => now(),
            ]);
    }

    private function assertValidGeneratedPdf(string $pdfBytes, DocumentGenerationRun $run, DocumentGenerationRunItem $item): void
    {
        $byteLength = strlen($pdfBytes);
        $startsWithPdf = str_starts_with($pdfBytes, '%PDF');

        if ($byteLength >= self::MIN_GENERATED_PDF_BYTES && $startsWithPdf) {
            return;
        }

        $this->logItemFailure($run, $item, 'GENERATION_FAILED', 'error', 'Custom document generation produced invalid PDF bytes', [
            'byte_length' => $byteLength,
            'starts_with_pdf' => $startsWithPdf,
        ]);

        throw new \RuntimeException('Renderer returned invalid PDF bytes.');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logItemFailure(
        DocumentGenerationRun $run,
        DocumentGenerationRunItem $item,
        string $errorCode,
        string $level,
        string $message,
        array $extra = [],
    ): void {
        $context = [
            'company_id' => $this->companyId,
            'generation_run_id' => $run->id,
            'run_item_id' => $item->id,
            'employee_id' => $item->employee_id,
            'template_id' => $run->document_generation_template_id,
            'template_version_id' => $run->document_generation_template_version_id,
            'error_code' => $errorCode,
            ...$extra,
        ];

        if ($level === 'warning') {
            Log::warning($message, $context);

            return;
        }

        Log::error($message, $context);
    }
}
