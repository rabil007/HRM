<?php

namespace App\Http\Controllers\Public\DocumentEsign;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocumentSignature\SubmitBulkDocumentSignatureRequest;
use App\Support\BulkDocuments\BulkDocumentSignatureRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\SubmitBulkDocumentSignature;
use Illuminate\Http\RedirectResponse;

class SubmitDocumentEsignController extends Controller
{
    public function __invoke(
        SubmitBulkDocumentSignatureRequest $request,
        string $token,
        SubmitBulkDocumentSignature $submit,
    ): RedirectResponse {
        $signatureRequest = BulkDocumentSignatureRosterQuery::findByToken($token);

        if ($signatureRequest === null) {
            abort(404);
        }

        $submit->handle(
            $signatureRequest,
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
        );

        $label = BulkDocumentTypeRegistry::find($signatureRequest->document_type_key)['label'];

        return redirect()
            ->back()
            ->with('success', "Your signed {$label} has been submitted for HR review.");
    }
}
