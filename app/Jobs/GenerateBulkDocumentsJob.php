<?php

namespace App\Jobs;

use App\Models\BulkDocumentGenerationRun;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\EmployeeDocuments\DocumentDeletionService;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

class GenerateBulkDocumentsJob implements ShouldQueue
{
    use Queueable;

    private const EMPLOYEES_PER_CHUNK = 12;

    public int $tries = 1;

    public int $timeout = 90;

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $employeeIds
     */
    public function __construct(
        public int $companyId,
        public int $userId,
        public string $documentTypeKey,
        public array $filters,
        public int $generationRunId,
        public bool $replaceExisting,
        public ?array $employeeIds = null,
        public ?int $afterEmployeeId = null,
        public int $cumulativeGenerated = 0,
        public int $cumulativeReplaced = 0,
        public int $cumulativeSkipped = 0,
        public int $cumulativeFailed = 0,
    ) {}

    public function handle(
        StoresEmployeeDocument $store,
        DocumentDeletionService $deletion,
    ): void {
        $definition = BulkDocumentTypeRegistry::find($this->documentTypeKey);
        $renderer = BulkDocumentTypeRegistry::resolveRenderer($this->documentTypeKey);

        $documentType = DocumentType::query()->firstOrCreate(
            ['title' => $definition['document_type_title']],
            ['is_active' => true],
        );

        $run = BulkDocumentGenerationRun::query()->find($this->generationRunId);

        if ($run === null) {
            return;
        }

        if ($this->afterEmployeeId === null) {
            $run->update([
                'status' => 'running',
                'started_at' => now(),
            ]);
        }

        $directoryFilters = EmployeeDirectoryFilters::fromArray($this->filters);

        $generated = 0;
        $replaced = 0;
        $skipped = 0;
        $failed = 0;
        $lastProcessedEmployeeId = $this->afterEmployeeId;

        $employees = BulkDocumentRosterQuery::employeeQuery(
            $this->companyId,
            $directoryFilters,
            $this->employeeIds,
        )
            ->when($this->afterEmployeeId !== null, function ($query): void {
                $query->where('id', '>', $this->afterEmployeeId);
            })
            ->limit(self::EMPLOYEES_PER_CHUNK)
            ->get();

        foreach ($employees as $employee) {
            $lastProcessedEmployeeId = $employee->id;

            $existingDocument = $this->findExistingDocument($employee, $documentType->id);

            if (! $this->replaceExisting && $existingDocument !== null) {
                $skipped++;

                continue;
            }

            try {
                $pdfBytes = $renderer->render($employee, $this->companyId);
                $filename = $this->buildFilename($employee);

                $tempPath = tempnam(sys_get_temp_dir(), 'bulk-doc-');
                file_put_contents($tempPath, $pdfBytes);

                try {
                    if ($existingDocument !== null) {
                        $deletion->delete($existingDocument);
                        $replaced++;
                    } else {
                        $generated++;
                    }

                    $uploadedFile = new UploadedFile(
                        $tempPath,
                        $filename,
                        'application/pdf',
                        null,
                        true,
                    );

                    $store->create(
                        $employee,
                        $documentType,
                        $uploadedFile,
                        ['title' => $documentType->title],
                        $this->companyId,
                        $this->userId,
                    );
                } finally {
                    if (is_file($tempPath)) {
                        @unlink($tempPath);
                    }
                }
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        $totalGenerated = $this->cumulativeGenerated + $generated;
        $totalReplaced = $this->cumulativeReplaced + $replaced;
        $totalSkipped = $this->cumulativeSkipped + $skipped;
        $totalFailed = $this->cumulativeFailed + $failed;

        $hasMore = $employees->count() === self::EMPLOYEES_PER_CHUNK
            && $lastProcessedEmployeeId !== null
            && BulkDocumentRosterQuery::employeeQuery(
                $this->companyId,
                $directoryFilters,
                $this->employeeIds,
            )
                ->where('id', '>', $lastProcessedEmployeeId)
                ->exists();

        $run->update([
            'generated_count' => $totalGenerated,
            'replaced_count' => $totalReplaced,
            'skipped_count' => $totalSkipped,
            'failed_count' => $totalFailed,
        ]);

        if ($hasMore && $lastProcessedEmployeeId !== null) {
            self::dispatch(
                $this->companyId,
                $this->userId,
                $this->documentTypeKey,
                $this->filters,
                $this->generationRunId,
                $this->replaceExisting,
                $this->employeeIds,
                $lastProcessedEmployeeId,
                $totalGenerated,
                $totalReplaced,
                $totalSkipped,
                $totalFailed,
            );

            return;
        }

        $run->update([
            'status' => $totalFailed > 0 && ($totalGenerated + $totalReplaced) === 0 ? 'failed' : 'completed',
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        BulkDocumentGenerationRun::query()
            ->where('id', $this->generationRunId)
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);

        report($exception);
    }

    private function findExistingDocument(Employee $employee, int $documentTypeId): ?EmployeeDocument
    {
        return EmployeeDocument::query()
            ->where('company_id', $this->companyId)
            ->where('employee_id', $employee->id)
            ->where('document_type_id', $documentTypeId)
            ->orderByDesc('id')
            ->first();
    }

    private function buildFilename(Employee $employee): string
    {
        $slug = Str::slug($employee->employee_no ?: (string) $employee->id);

        return match ($this->documentTypeKey) {
            'salary_certificate' => "salary-certificate-{$slug}.pdf",
            default => "salary-declaration-{$slug}.pdf",
        };
    }
}
