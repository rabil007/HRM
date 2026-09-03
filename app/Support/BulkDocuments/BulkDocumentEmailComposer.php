<?php

namespace App\Support\BulkDocuments;

use App\Mail\BulkDocumentMail;
use App\Models\BulkDocumentEmailSend;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class BulkDocumentEmailComposer
{
    /**
     * @param  list<string>  $ccRecipients
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function sendForEmployee(
        int $companyId,
        int $batchId,
        string $documentTypeKey,
        Employee $employee,
        Company $company,
        EmailTemplate $template,
        string $documentTypeLabel,
        int $documentTypeId,
        array $ccRecipients = [],
    ): array {
        $recipient = $this->resolveRecipient($employee);

        if ($recipient === null) {
            BulkDocumentEmailSend::query()->create([
                'batch_id' => $batchId,
                'employee_id' => $employee->id,
                'status' => 'skipped',
                'error' => 'No email address',
            ]);

            return ['sent' => 0, 'failed' => 0, 'skipped' => 1];
        }

        $document = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('document_type_id', $documentTypeId)
            ->orderByDesc('id')
            ->first();

        $resolved = $document === null
            ? null
            : EmployeePrivateFile::resolve(
                (string) $document->file_path,
                $companyId,
                EmployeePrivateFileKind::Document,
            );

        if ($document === null || $resolved === null) {
            BulkDocumentEmailSend::query()->create([
                'batch_id' => $batchId,
                'employee_id' => $employee->id,
                'employee_document_id' => $document?->id,
                'recipient_email' => $recipient,
                'status' => 'failed',
                'error' => 'Document not found',
            ]);

            return ['sent' => 0, 'failed' => 1, 'skipped' => 0];
        }

        if (BulkDocumentTypeRegistry::legacySigningRetired($documentTypeKey)) {
            BulkDocumentEmailSend::query()->create([
                'batch_id' => $batchId,
                'employee_id' => $employee->id,
                'employee_document_id' => $document->id,
                'recipient_email' => $recipient,
                'status' => 'failed',
                'error' => LegacySalaryDeclarationSigning::SIGNING_RETIREMENT_MESSAGE,
            ]);

            return ['sent' => 0, 'failed' => 1, 'skipped' => 0];
        }

        $subject = $this->substitute($template->subject, $employee, $company, $documentTypeLabel);
        $body = $this->substitute($template->body_html, $employee, $company, $documentTypeLabel);
        $filename = $this->attachmentFilename($documentTypeKey, $employee);
        $cc = $this->normalizeCcRecipients($ccRecipients, $recipient);

        try {
            Mail::to($recipient)->queue(new BulkDocumentMail(
                subjectLine: $subject,
                bodyMessage: $body,
                organizationName: (string) $company->name,
                attachmentDisk: $resolved->disk,
                attachmentPath: $resolved->path,
                attachmentName: $filename,
                includeCompanyFooter: (bool) $template->include_company_footer,
                ccRecipients: $cc,
            ));

            BulkDocumentEmailSend::query()->create([
                'batch_id' => $batchId,
                'employee_id' => $employee->id,
                'employee_document_id' => $document->id,
                'recipient_email' => $recipient,
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            return ['sent' => 1, 'failed' => 0, 'skipped' => 0];
        } catch (Throwable $exception) {
            report($exception);

            BulkDocumentEmailSend::query()->create([
                'batch_id' => $batchId,
                'employee_id' => $employee->id,
                'employee_document_id' => $document->id,
                'recipient_email' => $recipient,
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);

            return ['sent' => 0, 'failed' => 1, 'skipped' => 0];
        }
    }

    public function resolveRecipient(Employee $employee): ?string
    {
        if (filled($employee->work_email)) {
            return (string) $employee->work_email;
        }

        if (filled($employee->personal_email)) {
            return (string) $employee->personal_email;
        }

        return null;
    }

    public function substitute(
        string $template,
        Employee $employee,
        Company $company,
        string $documentTypeLabel,
        string $signatureUrl = '',
    ): string {
        return strtr($template, [
            '{{employee_name}}' => (string) $employee->name,
            '{{employee_no}}' => (string) ($employee->employee_no ?? ''),
            '{{company_name}}' => (string) $company->name,
            '{{document_type}}' => $documentTypeLabel,
            '{{signature_url}}' => $signatureUrl,
        ]);
    }

    public function attachmentFilename(string $documentTypeKey, Employee $employee): string
    {
        $slug = Str::slug($employee->employee_no ?: (string) $employee->id);

        return match ($documentTypeKey) {
            'salary_certificate' => "salary-certificate-{$slug}.pdf",
            default => "salary-declaration-{$slug}.pdf",
        };
    }

    /**
     * @param  list<string>  $ccRecipients
     * @return list<string>
     */
    public function normalizeCcRecipients(array $ccRecipients, string $recipient): array
    {
        $recipientLower = strtolower(trim($recipient));

        return collect($ccRecipients)
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => $email !== '' && strtolower($email) !== $recipientLower)
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();
    }
}
