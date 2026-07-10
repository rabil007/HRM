<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\EmailBulkDocumentsRequest;
use App\Jobs\SendBulkDocumentEmailsJob;
use App\Models\BulkDocumentEmailBatch;
use App\Models\Employee;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use Illuminate\Http\RedirectResponse;

class EmailBulkDocumentsController extends Controller
{
    public function store(EmailBulkDocumentsRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $userId = (int) $request->user()?->id;
        $documentTypeKey = (string) $request->input('document_type_key');

        BulkDocumentTypeRegistry::find($documentTypeKey);

        $template = BulkDocumentTypeRegistry::resolveEmailTemplate(
            $documentTypeKey,
            $request->emailIntent(),
        );

        if ($template === null) {
            return back()->withErrors([
                'email_template_id' => 'No enabled email template is configured for this document type.',
            ]);
        }

        $employeeIds = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereIn('id', $request->employeeIds())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (empty($employeeIds)) {
            return back()->withErrors([
                'employee_ids' => 'No valid active employees selected.',
            ]);
        }

        $batch = BulkDocumentEmailBatch::query()->create([
            'company_id' => $companyId,
            'document_type_key' => $documentTypeKey,
            'email_template_id' => $template->id,
            'subject' => $template->subject,
            'total_selected' => count($employeeIds),
            'status' => 'queued',
            'triggered_by' => $userId,
        ]);

        SendBulkDocumentEmailsJob::dispatch(
            $companyId,
            $batch->id,
            $documentTypeKey,
            $employeeIds,
            $request->ccRecipients(),
        );

        return back()->with('success', 'Email sending started for '.count($employeeIds).' employee(s).');
    }
}
