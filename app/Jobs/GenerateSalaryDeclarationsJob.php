<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\JobRun;
use App\Services\SalaryDeclaration\RendersSalaryDeclarationPdf;
use App\Support\BulkDocuments\SalaryDeclarationGenerationProgress;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

class GenerateSalaryDeclarationsJob implements ShouldQueue
{
    use Queueable;

    private const EMPLOYEES_PER_CHUNK = 12;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public int $companyId,
        public int $userId,
        public ?int $afterEmployeeId = null,
        public ?string $batchCorrelationId = null,
        public int $cumulativeGenerated = 0,
        public int $cumulativeSkipped = 0,
    ) {}

    public function handle(
        RendersSalaryDeclarationPdf $renderer,
        StoresEmployeeDocument $store,
    ): void {
        $company = Company::query()->find($this->companyId);
        $companyName = $company ? (string) $company->name : "Company #{$this->companyId}";

        $documentType = DocumentType::query()->firstOrCreate(
            ['title' => 'Salary Declaration'],
            ['is_active' => true],
        );

        $batchCorrelationId = $this->batchCorrelationId
            ?? ($this->job ? $this->job->uuid() : (string) Str::uuid());

        if ($this->afterEmployeeId === null) {
            SalaryDeclarationGenerationProgress::markRunning($this->companyId, $batchCorrelationId);
        }

        $generated = 0;
        $skipped = 0;
        $lastProcessedEmployeeId = $this->afterEmployeeId;

        $employees = Employee::query()
            ->where('company_id', $this->companyId)
            ->where('status', 'active')
            ->when($this->afterEmployeeId !== null, function ($query): void {
                $query->where('id', '>', $this->afterEmployeeId);
            })
            ->orderBy('id')
            ->limit(self::EMPLOYEES_PER_CHUNK)
            ->get();

        foreach ($employees as $employee) {
            $lastProcessedEmployeeId = $employee->id;

            if ($this->employeeAlreadyHasDeclaration($employee, $documentType->id)) {
                $skipped++;

                continue;
            }

            $pdfBytes = $renderer->render($employee, $this->companyId);
            $filename = 'salary-declaration-'.Str::slug($employee->employee_no ?: (string) $employee->id).'.pdf';

            $tempPath = tempnam(sys_get_temp_dir(), 'salary-decl-');
            file_put_contents($tempPath, $pdfBytes);

            try {
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

                $generated++;
            } finally {
                if (is_file($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }

        $totalGenerated = $this->cumulativeGenerated + $generated;
        $totalSkipped = $this->cumulativeSkipped + $skipped;

        $hasMore = $employees->count() === self::EMPLOYEES_PER_CHUNK
            && $lastProcessedEmployeeId !== null
            && Employee::query()
                ->where('company_id', $this->companyId)
                ->where('status', 'active')
                ->where('id', '>', $lastProcessedEmployeeId)
                ->exists();

        if ($hasMore && $lastProcessedEmployeeId !== null) {
            self::dispatch(
                $this->companyId,
                $this->userId,
                $lastProcessedEmployeeId,
                $batchCorrelationId,
                $totalGenerated,
                $totalSkipped,
            );

            $this->updateBatchJobRun(
                $batchCorrelationId,
                "Generated {$totalGenerated} salary declaration(s) for {$companyName} so far. Processing continues...",
                $companyName,
                $totalGenerated,
                $totalSkipped,
                running: true,
            );

            return;
        }

        if ($batchCorrelationId !== '') {
            $this->updateBatchJobRun(
                $batchCorrelationId,
                "Generated {$totalGenerated} salary declaration(s) for {$companyName}. Skipped {$totalSkipped} employee(s) with existing documents.",
                $companyName,
                $totalGenerated,
                $totalSkipped,
                running: false,
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        SalaryDeclarationGenerationProgress::markFailed(
            $this->companyId,
            'Salary declaration generation failed. Check Job Runs for details.',
        );

        report($exception);
    }

    private function employeeAlreadyHasDeclaration(Employee $employee, int $documentTypeId): bool
    {
        return EmployeeDocument::query()
            ->where('company_id', $this->companyId)
            ->where('employee_id', $employee->id)
            ->where('document_type_id', $documentTypeId)
            ->exists();
    }

    private function updateBatchJobRun(
        string $batchCorrelationId,
        string $message,
        string $companyName,
        int $generated,
        int $skipped,
        bool $running,
    ): void {
        JobRun::query()->where('correlation_id', $batchCorrelationId)->update([
            'message' => $message,
            'context' => [
                'company_id' => $this->companyId,
                'company_name' => $companyName,
                'generated' => $generated,
                'skipped' => $skipped,
            ],
        ]);

        SalaryDeclarationGenerationProgress::update($this->companyId, [
            'status' => $running ? 'running' : 'completed',
            'message' => $message,
            'generated' => $generated,
            'skipped' => $skipped,
            'finished_at' => $running ? null : now()->toIso8601String(),
        ]);
    }
}
