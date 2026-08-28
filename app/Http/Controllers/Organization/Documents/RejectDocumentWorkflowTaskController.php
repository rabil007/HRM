<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Exceptions\DocumentWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\RejectDocumentWorkflowTaskRequest;
use App\Models\DocumentWorkflowTask;
use App\Support\Documents\Workflow\Actions\RejectDocumentWorkflowTask;
use Illuminate\Http\RedirectResponse;

class RejectDocumentWorkflowTaskController extends Controller
{
    public function __invoke(
        RejectDocumentWorkflowTaskRequest $request,
        DocumentWorkflowTask $workflowTask,
        RejectDocumentWorkflowTask $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        try {
            $workflowRequest = $action->handle(
                task: $workflowTask,
                actor: $request->user(),
                companyId: $companyId,
                reason: (string) $request->validated('reason'),
            );
        } catch (DocumentWorkflowException $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('organization.documents.requests.show', ['workflowRequest' => $workflowRequest->id])
            ->with('success', 'Decision recorded.');
    }
}
