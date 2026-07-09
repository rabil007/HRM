<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\RejectBulkDocumentSignatureRequest;
use App\Models\BulkDocumentSignatureRequest;
use App\Support\BulkDocuments\ReviewBulkDocumentSignature;
use Illuminate\Http\RedirectResponse;

class RejectBulkDocumentSignatureController extends Controller
{
    public function __invoke(
        RejectBulkDocumentSignatureRequest $request,
        BulkDocumentSignatureRequest $signatureRequest,
        ReviewBulkDocumentSignature $review,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($signatureRequest->company_id === $companyId, 404);

        $review->reject(
            $signatureRequest,
            (int) $request->user()->id,
            (string) $request->validated('reason'),
        );

        return back()->with('success', 'Signature request rejected.');
    }
}
