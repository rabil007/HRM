<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\JobRun;
use App\Services\SalaryDeclaration\RendersSalaryDeclarationPdf;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

class GenerateSalaryDeclarationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public int $companyId,
        public int $userId,
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

        $generated = 0;
        $skipped = 0;

        Employee::query()
            ->where('company_id', $this->companyId)
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(25, function ($employees) use (
                $documentType,
                $renderer,
                $store,
                &$generated,
                &$skipped,
            ): void {
                foreach ($employees as $employee) {
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
            });

        $jobId = $this->job ? $this->job->uuid() : null;

        if ($jobId) {
            JobRun::query()->where('correlation_id', $jobId)->update([
                'message' => "Generated {$generated} salary declaration(s) for {$companyName}. Skipped {$skipped} employee(s) with existing documents.",
                'context' => [
                    'company_id' => $this->companyId,
                    'company_name' => $companyName,
                    'generated' => $generated,
                    'skipped' => $skipped,
                ],
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
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
}
