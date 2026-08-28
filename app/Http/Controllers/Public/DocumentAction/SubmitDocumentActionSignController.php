<?php

namespace App\Http\Controllers\Public\DocumentAction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\DocumentAction\SubmitDocumentActionSignRequest;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientSignature;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestAccess;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use Illuminate\Http\RedirectResponse;

class SubmitDocumentActionSignController extends Controller
{
    public function __invoke(
        SubmitDocumentActionSignRequest $request,
        string $token,
        SubmitDocumentRecipientSignature $submitSignature,
    ): RedirectResponse {
        $recipientRequest = DocumentRecipientRequestToken::findByRawToken($token);

        if ($recipientRequest === null) {
            abort(404);
        }

        DocumentRecipientRequestAccess::assertPublicTokenRecipient($recipientRequest);

        $submitSignature->handle($recipientRequest, $request->validated(), $request);

        return redirect()
            ->route('public.document-action.show', ['token' => $token])
            ->with('success', 'Your signature has been submitted successfully.');
    }
}
