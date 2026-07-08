<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\EmailBulkDocumentsRequest;
use App\Models\Employee;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\SendBulkDocumentEmails;
use Illuminate\Http\RedirectResponse;

class EmailBulkDocumentsController extends Controller
{
    public function store(
        EmailBulkDocumentsRequest $request,
        SendBulkDocumentEmails $sender,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $userId = (int) $request->user()?->id;
        $documentTypeKey = (string) $request->input('document_type_key');

        BulkDocumentTypeRegistry::find($documentTypeKey);

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $request->employeeIds())
            ->get();

        $template = $sender->resolveTemplate(
            $request->filled('email_template_id') ? (int) $request->input('email_template_id') : null,
        );

        $result = $sender->handle(
            $companyId,
            $userId,
            $documentTypeKey,
            $employees,
            $template,
        );

        $message = "Email queued for {$result['sent']} employee(s).";

        if ($result['skipped_no_email'] > 0) {
            $message .= " {$result['skipped_no_email']} skipped (no email).";
        }

        if ($result['failed'] > 0) {
            $message .= " {$result['failed']} failed.";
        }

        return back()->with('success', $message);
    }
}
