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
        $user = $request->user();

        abort_unless(
            DocumentRecipientRequestAccess::canViewRequest($recipientRequest, $user, $companyId),
            403,
        );

        $canViewAudit = $user?->can('audit.view') ?? false;

        return Inertia::render('organization/documents/recipient-requests/show', [
            'recipient_request' => $presenter->detail($recipientRequest),
            'can' => DocumentRecipientRequestPagePermissions::for($user),
            'recent_activity' => $canViewAudit
                ? RecentActivityQuery::for($user, $companyId, DocumentRecipientRequest::class, $recipientRequest->id)
                : [],
            'can_view_audit' => $canViewAudit,
        ]);
    }
}
