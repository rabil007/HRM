<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentEmailBatch;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Employee;
use Illuminate\Support\Collection;

final class SendBulkDocumentEmails
{
    public function __construct(
        private BulkDocumentEmailComposer $composer,
    ) {}

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  list<string>  $ccRecipients
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
            'status' => 'running',
            'started_at' => now(),
            'triggered_by' => $userId,
        ]);

        $sent = 0;
        $failed = 0;
        $skippedNoEmail = 0;

        foreach ($employees as $employee) {
            $result = $this->composer->sendForEmployee(
                $companyId,
                $batch->id,
                $documentTypeKey,
                $employee,
                $company,
                $template,
                $definition['label'],
                $documentType->id,
                $ccRecipients,
            );

            $sent += $result['sent'];
            $failed += $result['failed'];
            $skippedNoEmail += $result['skipped'];
        }

        $batch->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_no_email_count' => $skippedNoEmail,
            'status' => 'completed',
            'finished_at' => now(),
        ]);

        return [
            'batch_id' => $batch->id,
            'sent' => $sent,
            'failed' => $failed,
            'skipped_no_email' => $skippedNoEmail,
        ];
    }
}
