<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\CancelDocumentRecipientRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CancelDocumentRecipientRequestController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentRecipientRequest $recipientRequest,
        CancelDocumentRecipientRequest $cancelRequest,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $cancelRequest->handle($recipientRequest, $request->user(), $companyId);

        return back()->with('success', 'Recipient request cancelled.');
    }
}
