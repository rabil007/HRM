<?php

namespace App\Support\BulkDocuments;

use App\Mail\BulkDocumentMail;
use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentEmailSend;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SendBulkDocumentEmails
{
    /**
     * @param  Collection<int, Employee>  $employees
     * @return array{
     *     batch_id: int,
     *     sent: int,
     *     failed: int,
     *     skipped_no_email: int
     * }
     */
    public function handle(
        int $companyId,
        int $userId,
        string $documentTypeKey,
        Collection $employees,
        EmailTemplate $template,
        array $ccRecipients = [],
    ): array {
        $definition = BulkDocumentTypeRegistry::find($documentTypeKey);
        $documentType = BulkDocumentTypeRegistry::resolveDocumentType($documentTypeKey);
        $company = Company::query()->findOrFail($companyId);

        $batch = BulkDocumentEmailBatch::query()->create([
            'company_id' => $companyId,
            'document_type_key' => $documentTypeKey,
            'email_template_id' => $template->id,
            'subject' => $template->subject,
            'total_selected' => $employees->count(),
            'triggered_by' => $userId,
        ]);

        $sent = 0;
        $failed = 0;
        $skippedNoEmail = 0;

        foreach ($employees as $employee) {
            $recipient = filled($employee->work_email)
                ? (string) $employee->work_email
                : (filled($employee->personal_email) ? (string) $employee->personal_email : null);

            if ($recipient === null) {
                $skippedNoEmail++;
                BulkDocumentEmailSend::query()->create([
                    'batch_id' => $batch->id,
                    'employee_id' => $employee->id,
                    'status' => 'skipped',
                    'error' => 'No email address',
                ]);

                continue;
            }

            $document = EmployeeDocument::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->where('document_type_id', $documentType->id)
                ->orderByDesc('id')
                ->first();

            if ($document === null || ! Storage::disk('public')->exists((string) $document->file_path)) {
                $failed++;
                BulkDocumentEmailSend::query()->create([
                    'batch_id' => $batch->id,
                    'employee_id' => $employee->id,
                    'employee_document_id' => $document?->id,
                    'recipient_email' => $recipient,
                    'status' => 'failed',
                    'error' => 'Document not found',
                ]);

                continue;
            }

            $subject = $this->substitute($template->subject, $employee, $company, $definition['label']);
            $body = $this->substitute($template->body_html, $employee, $company, $definition['label']);
            $filename = $this->attachmentFilename($documentTypeKey, $employee);
            $cc = $this->normalizeCcRecipients($ccRecipients, $recipient);

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
                    'batch_id' => $batch->id,
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
                    'batch_id' => $batch->id,
                    'employee_id' => $employee->id,
                    'employee_document_id' => $document->id,
                    'recipient_email' => $recipient,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $batch->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_no_email_count' => $skippedNoEmail,
        ]);

        return [
            'batch_id' => $batch->id,
            'sent' => $sent,
            'failed' => $failed,
            'skipped_no_email' => $skippedNoEmail,
        ];
    }

    public function resolveTemplate(?int $emailTemplateId): EmailTemplate
    {
        if ($emailTemplateId !== null) {
            $template = EmailTemplate::query()
                ->where('id', $emailTemplateId)
                ->where('enabled', true)
                ->first();

            if ($template === null) {
                throw ValidationException::withMessages([
                    'email_template_id' => 'The selected email template is not available.',
                ]);
            }

            return $template;
        }

        $template = EmailTemplate::query()
            ->where('enabled', true)
            ->whereIn('category', ['document', 'payroll'])
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->first();

        if ($template === null) {
            throw ValidationException::withMessages([
                'email_template_id' => 'No enabled email template is configured.',
            ]);
        }

        return $template;
    }

    private function substitute(string $template, Employee $employee, Company $company, string $documentTypeLabel): string
    {
        return strtr($template, [
            '{{employee_name}}' => (string) $employee->name,
            '{{employee_no}}' => (string) ($employee->employee_no ?? ''),
            '{{company_name}}' => (string) $company->name,
            '{{document_type}}' => $documentTypeLabel,
        ]);
    }

    private function attachmentFilename(string $documentTypeKey, Employee $employee): string
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
    private function normalizeCcRecipients(array $ccRecipients, string $recipient): array
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
