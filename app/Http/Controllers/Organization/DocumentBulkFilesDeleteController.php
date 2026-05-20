<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\EmployeeDocuments\DocumentBulkActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentBulkFilesDeleteController extends Controller
{
    public function __invoke(
        Request $request,
        Employee $employee,
        DocumentBulkActionService $bulkActions,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $validated = $request->validate([
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer', 'distinct'],
        ]);

        $deleted = $bulkActions->deleteDocuments(
            $validated['document_ids'],
            $companyId,
            $employee->id,
        );

        if ($deleted === 0) {
            return back()->with('error', 'No documents could be deleted.');
        }

        $label = $deleted === 1 ? '1 document' : "{$deleted} documents";

        return back()->with('success', "Deleted {$label}.");
    }
}
