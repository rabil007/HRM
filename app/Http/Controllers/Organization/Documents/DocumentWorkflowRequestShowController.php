<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentWorkflowRequest;
use App\Models\EmployeeDocument;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Documents\Workflow\DocumentWorkflowAccess;
use App\Support\Documents\Workflow\DocumentWorkflowPagePermissions;
use App\Support\Documents\Workflow\DocumentWorkflowPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentWorkflowRequestShowController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentWorkflowRequest $workflowRequest,
        DocumentWorkflowPresenter $presenter,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless($request->user()?->can('documents.requests.view'), 403);
        DocumentWorkflowAccess::assertRequestInCompany($workflowRequest, $companyId);

        $detail = $presenter->detail($workflowRequest, $request->user());
        $documentId = $detail['document']['id'] ?? null;

        return Inertia::render('organization/documents/requests/show', [
            'request' => $detail,
            'can' => DocumentWorkflowPagePermissions::for($request->user()),
            'recent_activity' => $documentId !== null
                ? RecentActivityQuery::for(
                    $request->user(),
                    $companyId,
                    EmployeeDocument::class,
                    (int) $documentId,
                    limit: 20,
                )
                : [],
            'can_view_audit' => $request->user()?->can('audit.view') ?? false,
        ]);
    }
}
