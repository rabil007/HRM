<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Exceptions\DocumentWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\CompleteDocumentWorkflowTaskRequest;
use App\Models\DocumentWorkflowTask;
use App\Support\Documents\Workflow\Actions\CompleteDocumentWorkflowTask;
use Illuminate\Http\RedirectResponse;

class CompleteDocumentWorkflowTaskController extends Controller
{
    public function __invoke(
        CompleteDocumentWorkflowTaskRequest $request,
        DocumentWorkflowTask $workflowTask,
        CompleteDocumentWorkflowTask $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        try {
            $workflowRequest = $action->handle(
                task: $workflowTask,
                actor: $request->user(),
                companyId: $companyId,
                notes: $request->validated('notes'),
            );
        } catch (DocumentWorkflowException $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('organization.documents.requests.show', ['workflowRequest' => $workflowRequest->id])
            ->with('success', 'Decision recorded.');
    }
}
