<?php

namespace App\Http\Controllers\Public\DocumentAction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\DocumentAction\SubmitDocumentActionAcknowledgeRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientAcknowledgement;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use Illuminate\Http\RedirectResponse;

class SubmitDocumentActionAcknowledgeController extends Controller
{
    public function __invoke(
        SubmitDocumentActionAcknowledgeRequest $request,
        string $token,
        SubmitDocumentRecipientAcknowledgement $submitAcknowledgement,
    ): RedirectResponse {
        $recipientRequest = DocumentRecipientRequestToken::findByRawToken($token);

        if ($recipientRequest === null) {
            abort(404);
        }

        $submitAcknowledgement->handle($recipientRequest, $request->validated(), $request);

        return redirect()
            ->route('public.document-action.show', ['token' => $token])
            ->with('success', 'Your acknowledgement has been recorded successfully.');
    }
}
