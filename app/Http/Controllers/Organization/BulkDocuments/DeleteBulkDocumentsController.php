<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\DeleteBulkDocumentsRequest;
use App\Support\BulkDocuments\BulkDocumentsDeletionQuery;
use App\Support\EmployeeDocuments\DocumentDeletionService;
use Illuminate\Http\RedirectResponse;

class DeleteBulkDocumentsController extends Controller
{
    public function destroy(
        DeleteBulkDocumentsRequest $request,
        DocumentDeletionService $deletion,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $documents = BulkDocumentsDeletionQuery::forType(
            $companyId,
            (string) $request->input('document_type_key'),
            $request->documentIds(),
        );

        $deleted = 0;

        foreach ($documents as $document) {
            $deletion->delete($document);
            $deleted++;
        }

        return back()->with(
            'success',
            $deleted > 0
                ? "Removed {$deleted} document(s)."
                : 'No documents were removed.',
        );
    }
}
