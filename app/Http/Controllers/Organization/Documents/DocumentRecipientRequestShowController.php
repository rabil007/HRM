<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentRecipientRequest;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestAccess;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestPagePermissions;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentRecipientRequestShowController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentRecipientRequest $recipientRequest,
        DocumentRecipientRequestPresenter $presenter,
    ): Response {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless($request->user()?->can('documents.recipient-requests.view'), 403);
        DocumentRecipientRequestAccess::assertInCompany($recipientRequest, $companyId);

        $canViewAudit = $request->user()?->can('audit.view') ?? false;

        return Inertia::render('organization/documents/recipient-requests/show', [
            'recipient_request' => $presenter->detail($recipientRequest),
            'can' => DocumentRecipientRequestPagePermissions::for($request->user()),
            'recent_activity' => $canViewAudit
                ? RecentActivityQuery::for($request->user(), $companyId, DocumentRecipientRequest::class, $recipientRequest->id)
                : [],
            'can_view_audit' => $canViewAudit,
        ]);
    }
}
