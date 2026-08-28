<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Exceptions\DocumentWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\CancelDocumentWorkflowRequestRequest;
use App\Models\DocumentWorkflowRequest;
use App\Support\Documents\Workflow\Actions\CancelDocumentWorkflowRequest;
use App\Support\Documents\Workflow\DocumentWorkflowAccess;
use Illuminate\Http\RedirectResponse;

class CancelDocumentWorkflowRequestController extends Controller
{
    public function __invoke(
        CancelDocumentWorkflowRequestRequest $request,
        DocumentWorkflowRequest $workflowRequest,
        CancelDocumentWorkflowRequest $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        DocumentWorkflowAccess::assertRequestInCompany($workflowRequest, $companyId);

        try {
            $action->handle(
                request: $workflowRequest,
                actor: $request->user(),
                companyId: $companyId,
                reason: $request->validated('reason'),
            );
        } catch (DocumentWorkflowException $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('organization.documents.requests.show', ['workflowRequest' => $workflowRequest->id])
            ->with('success', 'Workflow request cancelled.');
    }
}
