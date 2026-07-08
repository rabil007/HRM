<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\DeleteBulkDocumentsRequest;
use App\Models\EmployeeDocument;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\EmployeeDocuments\DocumentDeletionService;
use Illuminate\Http\RedirectResponse;

class DeleteBulkDocumentsController extends Controller
{
    public function destroy(
        DeleteBulkDocumentsRequest $request,
        DocumentDeletionService $deletion,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $documentType = BulkDocumentTypeRegistry::resolveDocumentType(
            (string) $request->input('document_type_key'),
        );

        $documents = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('document_type_id', $documentType->id)
            ->whereIn('id', $request->documentIds())
            ->get();

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
