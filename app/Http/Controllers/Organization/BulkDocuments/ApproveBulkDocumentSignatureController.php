<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Models\BulkDocumentSignatureRequest;
use App\Support\BulkDocuments\ReviewBulkDocumentSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApproveBulkDocumentSignatureController extends Controller
{
    public function __invoke(
        Request $request,
        BulkDocumentSignatureRequest $signatureRequest,
        ReviewBulkDocumentSignature $review,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($signatureRequest->company_id === $companyId, 404);

        $review->approve($signatureRequest, (int) $request->user()->id);

        return back()->with('success', 'Signed declaration approved and document updated.');
    }
}
