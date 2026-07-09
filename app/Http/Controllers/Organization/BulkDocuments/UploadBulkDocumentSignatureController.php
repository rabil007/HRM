<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\UploadBulkDocumentSignatureRequest;
use App\Models\BulkDocumentSignatureRequest;
use App\Support\BulkDocuments\UploadManualSignedDocument;
use Illuminate\Http\RedirectResponse;

class UploadBulkDocumentSignatureController extends Controller
{
    public function __invoke(
        UploadBulkDocumentSignatureRequest $request,
        BulkDocumentSignatureRequest $signatureRequest,
        UploadManualSignedDocument $upload,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($signatureRequest->company_id === $companyId, 404);

        $upload->handle(
            $signatureRequest,
            $request->file('file'),
            (int) $request->user()->id,
        );

        return back()->with('success', 'Signed document uploaded and queued for review.');
    }
}
