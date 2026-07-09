<?php

namespace App\Jobs;

use App\Models\BulkDocumentEmailBatch;
use App\Models\Company;
use App\Models\Employee;
use App\Support\BulkDocuments\BulkDocumentEmailComposer;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendBulkDocumentEmailsJob implements ShouldQueue
{
    use Queueable;

    private const EMPLOYEES_PER_CHUNK = 20;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  list<int>  $employeeIds
     * @param  list<string>  $ccRecipients
     */
    public function __construct(
        public int $companyId,
        public int $batchId,
        public string $documentTypeKey,
        public array $employeeIds,
        public array $ccRecipients = [],
        public int $afterEmployeeId = 0,
        public int $cumulativeSent = 0,
        public int $cumulativeFailed = 0,
        public int $cumulativeSkipped = 0,
    ) {}

    public function handle(BulkDocumentEmailComposer $composer): void
    {
        $batch = BulkDocumentEmailBatch::query()->find($this->batchId);

        if ($batch === null) {
            return;
        }

        if ($this->afterEmployeeId === 0) {
            $batch->update([
                'status' => 'running',
                'started_at' => now(),
            ]);
        }

        $definition = BulkDocumentTypeRegistry::find($this->documentTypeKey);
        $documentType = BulkDocumentTypeRegistry::resolveDocumentType($this->documentTypeKey);
        $company = Company::query()->findOrFail($this->companyId);
        $template = BulkDocumentTypeRegistry::resolveEmailTemplate($this->documentTypeKey);

        if ($template === null) {
            $batch->update(['status' => 'failed', 'finished_at' => now()]);

            return;
        }

        $employees = Employee::query()
            ->where('company_id', $this->companyId)
            ->where('status', 'active')
            ->whereIn('id', $this->employeeIds)
            ->where('id', '>', $this->afterEmployeeId)
            ->orderBy('id')
            ->limit(self::EMPLOYEES_PER_CHUNK)
            ->get();

        $sent = 0;
        $failed = 0;
        $skippedNoEmail = 0;
        $lastProcessedEmployeeId = $this->afterEmployeeId;

        foreach ($employees as $employee) {
            $lastProcessedEmployeeId = $employee->id;

            $result = $composer->sendForEmployee(
                $this->companyId,
                $this->batchId,
                $this->documentTypeKey,
                $employee,
                $company,
                $template,
                $definition['label'],
                $documentType->id,
                $this->ccRecipients,
            );

            $sent += $result['sent'];
            $failed += $result['failed'];
            $skippedNoEmail += $result['skipped'];
        }

        $totalSent = $this->cumulativeSent + $sent;
        $totalFailed = $this->cumulativeFailed + $failed;
        $totalSkipped = $this->cumulativeSkipped + $skippedNoEmail;

        $hasMore = $employees->count() === self::EMPLOYEES_PER_CHUNK;

        $batch->update([
            'sent_count' => $totalSent,
            'failed_count' => $totalFailed,
            'skipped_no_email_count' => $totalSkipped,
        ]);

        if ($hasMore) {
            self::dispatch(
                $this->companyId,
                $this->batchId,
                $this->documentTypeKey,
                $this->employeeIds,
                $this->ccRecipients,
                $lastProcessedEmployeeId,
                $totalSent,
                $totalFailed,
                $totalSkipped,
            );

            return;
        }

        $batch->update([
            'status' => 'completed',
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        BulkDocumentEmailBatch::query()
            ->where('id', $this->batchId)
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);

        report($exception);
    }
}
