<?php

namespace App\Jobs;

use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\Employee;
use App\Services\Documents\ContentTemplatePdfRenderer;
use App\Support\Documents\Actions\SyncGeneratedEmployeeDocument;
use App\Support\Documents\DocumentInstanceStorage;
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
        ContentTemplatePdfRenderer $contentRenderer,
        SyncGeneratedEmployeeDocument $syncEmployeeDoc,
    ): void {
        /** @var DocumentGenerationRun|null $run */
        $run = DocumentGenerationRun::query()
            ->forCompany($this->companyId)
            ->with(['template', 'templateVersion'])
            ->find($this->runId);

        if ($run === null) {
            return;
        }

        if ($this->afterRunItemId === null) {
            $run->update([
                'status' => 'running',
                'started_at' => now(),
            ]);
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
            || ! $template->isContent()
        ) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);

            return;
        }

        $lastProcessedItemId = $this->afterRunItemId;

        $items = DocumentGenerationRunItem::query()
            ->where('company_id', $this->companyId)
            ->where('document_generation_run_id', $run->id)
            ->when($this->afterRunItemId !== null, fn ($q) => $q->where('id', '>', $this->afterRunItemId))
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
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

            /** @var Employee|null $employee */
            $employee = Employee::query()
                ->where('company_id', $this->companyId)
                ->find($item->employee_id);

            if ($employee === null) {
                $item->update([
                    'status' => 'failed',
                    'error_code' => 'EMPLOYEE_NOT_FOUND',
                    'error_message' => 'Employee record could not be found.',
                ]);

                continue;
            }

            // Check if already generated for this exact template version
            $hasExistingInstance = DocumentInstance::query()
                ->forCompany($this->companyId)
                ->where('employee_id', $employee->id)
                ->where('document_generation_template_version_id', $version->id)
                ->exists();

            if (! $this->allowRepeatGeneration && $hasExistingInstance) {
                $item->update(['status' => 'skipped']);

                continue;
            }

            $tempPdfPath = null;
            $canonicalPath = null;
            $libraryFilePath = null;

            try {
                // 1. Render temp PDF
                $pdfBytes = $contentRenderer->render($template, $version, $employee, $this->companyId);

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
                DB::transaction(function () use (
                    $employee,
                    $template,
                    $version,
                    $run,
                    $item,
                    $artifact,
                    $storedLibraryDoc,
                    $syncEmployeeDoc,
                ): void {
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
                });
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
                    'error_message' => 'Failed to render or store document for this employee.',
                ]);

                Log::error('Custom document generation failed for run item', [
                    'run_id' => $run->id,
                    'item_id' => $item->id,
                    'employee_id' => $item->employee_id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                if ($tempPdfPath !== null && file_exists($tempPdfPath)) {
                    @unlink($tempPdfPath);
                }
            }
        }

        $counts = DocumentGenerationRunItem::query()
            ->where('company_id', $this->companyId)
            ->where('document_generation_run_id', $run->id)
            ->selectRaw("
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as generated,
                COUNT(CASE WHEN status = 'skipped' THEN 1 END) as skipped,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(CASE WHEN status IN ('pending', 'processing') THEN 1 END) as remaining
            ")
            ->first();

        $totalGenerated = (int) ($counts->generated ?? 0);
        $totalSkipped = (int) ($counts->skipped ?? 0);
        $totalFailed = (int) ($counts->failed ?? 0);
        $remaining = (int) ($counts->remaining ?? 0);

        $run->update([
            'generated_count' => $totalGenerated,
            'skipped_count' => $totalSkipped,
            'failed_count' => $totalFailed,
        ]);

        $hasMore = $remaining > 0
            && $lastProcessedItemId !== null
            && DocumentGenerationRunItem::query()
                ->where('company_id', $this->companyId)
                ->where('document_generation_run_id', $run->id)
                ->where('id', '>', $lastProcessedItemId)
                ->exists();

        if ($hasMore && $lastProcessedItemId !== null) {
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

        if ($remaining === 0) {
            $run->update([
                'status' => $totalFailed > 0 && $totalGenerated === 0 ? 'failed' : 'completed',
                'finished_at' => now(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        DocumentGenerationRun::query()
            ->where('id', $this->runId)
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);

        report($exception);
    }
}
