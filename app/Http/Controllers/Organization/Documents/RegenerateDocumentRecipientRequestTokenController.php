<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\RegenerateDocumentRecipientRequestToken;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegenerateDocumentRecipientRequestTokenController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentRecipientRequest $recipientRequest,
        RegenerateDocumentRecipientRequestToken $regenerateToken,
        DocumentRecipientRequestLinkService $links,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $result = $regenerateToken->handle($recipientRequest, $request->user(), $companyId);

        return back()->with([
            'recipient_request_link_regenerated' => [
                'id' => $result['request']->id,
                'secure_url' => $links->publicUrl($result['raw_token']),
            ],
        ]);
    }
}
