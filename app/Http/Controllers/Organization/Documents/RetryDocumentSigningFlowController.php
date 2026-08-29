<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentSigningFlow;
use App\Support\Documents\Signing\Actions\RetryDocumentSigningFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RetryDocumentSigningFlowController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentSigningFlow $signingFlow,
        RetryDocumentSigningFlow $action,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.recipient-requests.create') ?? false, 403);
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $signingFlow->company_id === $companyId, 404);

        $action->handle($signingFlow, $request->user(), $companyId);

        return back()->with('success', 'Signing flow retry attempted.');
    }
}
