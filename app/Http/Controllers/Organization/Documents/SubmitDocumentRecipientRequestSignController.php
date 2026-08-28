<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\DocumentAction\SubmitDocumentActionSignRequest;
use App\Models\DocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestAccess;
use Illuminate\Http\RedirectResponse;

class SubmitDocumentRecipientRequestSignController extends Controller
{
    public function __invoke(
        SubmitDocumentActionSignRequest $request,
        DocumentRecipientRequest $recipientRequest,
        SubmitDocumentRecipientSignature $submitSignature,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();

        abort_if($user === null, 403);

        DocumentRecipientRequestAccess::assertAssignedCompanySignatory($recipientRequest, $user, $companyId);

        $submitSignature->handle(
            $recipientRequest,
            $request->validated(),
            $request,
            $user,
        );

        return redirect()
            ->route('organization.documents.recipient-requests.respond', [
                'recipientRequest' => $recipientRequest->id,
            ])
            ->with('success', 'Your signature has been submitted successfully.');
    }
}
