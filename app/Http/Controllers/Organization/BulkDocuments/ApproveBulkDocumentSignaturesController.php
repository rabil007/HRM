<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\ApproveBulkDocumentSignaturesRequest;
use App\Support\BulkDocuments\ApproveBulkDocumentSignatures;
use Illuminate\Http\RedirectResponse;

class ApproveBulkDocumentSignaturesController extends Controller
{
    public function __invoke(
        ApproveBulkDocumentSignaturesRequest $request,
        ApproveBulkDocumentSignatures $approve,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $documentTypeKey = (string) $request->input('document_type_key', 'salary_declaration');

        $result = $approve->handle(
            $companyId,
            (int) $request->user()->id,
            $documentTypeKey,
            $request->signatureRequestIds(),
        );

        if ($result['approved'] === 0) {
            return back()->with('info', 'No submitted signatures were approved.');
        }

        return back()->with(
            'success',
            'Approved '.$result['approved'].' signature request(s).',
        );
    }
}
