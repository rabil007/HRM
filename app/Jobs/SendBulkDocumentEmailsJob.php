<?php

namespace App\Jobs;

use App\Mail\BulkDocumentMail;
use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentEmailSend;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\BulkDocuments\BulkDocumentSignatureLinkService;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\CreateBulkDocumentSignatureRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function handle(
        CreateBulkDocumentSignatureRequest $createSignatureRequest,
        BulkDocumentSignatureLinkService $signatureLinks,
    ): void {
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

            $recipient = filled($employee->work_email)
                ? (string) $employee->work_email
                : (filled($employee->personal_email) ? (string) $employee->personal_email : null);

            if ($recipient === null) {
                $skippedNoEmail++;
                BulkDocumentEmailSend::query()->create([
                    'batch_id' => $this->batchId,
                    'employee_id' => $employee->id,
                    'status' => 'skipped',
                    'error' => 'No email address',
                ]);

                continue;
            }

            $document = EmployeeDocument::query()
                ->where('company_id', $this->companyId)
                ->where('employee_id', $employee->id)
                ->where('document_type_id', $documentType->id)
                ->orderByDesc('id')
                ->first();

            if ($document === null || ! Storage::disk('public')->exists((string) $document->file_path)) {
                $failed++;
                BulkDocumentEmailSend::query()->create([
                    'batch_id' => $this->batchId,
                    'employee_id' => $employee->id,
                    'employee_document_id' => $document?->id,
                    'recipient_email' => $recipient,
                    'status' => 'failed',
                    'error' => 'Document not found',
                ]);

                continue;
            }

            $signatureRequest = null;
            $signatureUrl = '';

            if (BulkDocumentTypeRegistry::supportsEsignature($this->documentTypeKey)) {
                $signatureRequest = $createSignatureRequest->handle(
                    $this->companyId,
                    $employee->id,
                    $document,
                    $this->documentTypeKey,
                    $this->batchId,
                );
                $signatureUrl = $signatureLinks->signUrl($signatureRequest);
            }

            $subject = $this->substitute($template->subject, $employee, $company, $definition['label'], $signatureUrl);
            $body = $this->substitute($template->body_html, $employee, $company, $definition['label'], $signatureUrl);
            $filename = $this->buildFilename($employee);
            $cc = $this->normalizeCcRecipients($recipient);

            try {
                Mail::to($recipient)->queue(new BulkDocumentMail(
                    subjectLine: $subject,
                    bodyMessage: $body,
                    organizationName: (string) $company->name,
                    attachmentPath: (string) $document->file_path,
                    attachmentName: $filename,
                    includeCompanyFooter: (bool) $template->include_company_footer,
                    ccRecipients: $cc,
                ));

                $sent++;
                BulkDocumentEmailSend::query()->create([
                    'batch_id' => $this->batchId,
                    'employee_id' => $employee->id,
                    'employee_document_id' => $document->id,
                    'recipient_email' => $recipient,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            } catch (Throwable $exception) {
                $failed++;
                report($exception);

                BulkDocumentEmailSend::query()->create([
                    'batch_id' => $this->batchId,
                    'employee_id' => $employee->id,
                    'employee_document_id' => $document->id,
                    'recipient_email' => $recipient,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ]);
            }
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

    private function substitute(string $template, Employee $employee, Company $company, string $documentTypeLabel, string $signatureUrl = ''): string
    {
        return strtr($template, [
            '{{employee_name}}' => (string) $employee->name,
            '{{employee_no}}' => (string) ($employee->employee_no ?? ''),
            '{{company_name}}' => (string) $company->name,
            '{{document_type}}' => $documentTypeLabel,
            '{{signature_url}}' => $signatureUrl,
        ]);
    }

    private function buildFilename(Employee $employee): string
    {
        $slug = Str::slug($employee->employee_no ?: (string) $employee->id);

        return match ($this->documentTypeKey) {
            'salary_certificate' => "salary-certificate-{$slug}.pdf",
            default => "salary-declaration-{$slug}.pdf",
        };
    }

    /**
     * @return list<string>
     */
    private function normalizeCcRecipients(string $recipient): array
    {
        $recipientLower = strtolower(trim($recipient));

        return collect($this->ccRecipients)
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => $email !== '' && strtolower($email) !== $recipientLower)
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();
    }
}
