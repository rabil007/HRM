<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Exceptions\DocumentWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\StoreDocumentWorkflowRequestRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationGuard;
use App\Support\Documents\Workflow\Actions\CreateDocumentWorkflowRequest;
use App\Support\Documents\Workflow\Actions\CreateDocumentWorkflowRequestFromPreset;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class CreateDocumentWorkflowRequestController extends Controller
{
    public function __invoke(
        StoreDocumentWorkflowRequestRequest $request,
        Employee $employee,
        EmployeeDocument $document,
        CreateDocumentWorkflowRequest $action,
        CreateDocumentWorkflowRequestFromPreset $createFromPreset,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);
        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId, 404);
        DocumentAccess::assertDocumentInCompany($document, $companyId);

        $document->loadMissing('documentInstance');
        $instance = $document->documentInstance;

        if ($instance !== null) {
            app(DocumentLifecycleAutomationGuard::class)->assertManualWorkflowAllowed($instance, $companyId);
        }

        try {
            if ($request->filled('workflow_preset_id')) {
                $document->loadMissing('employee');
                $subjectEmployee = $document->employee ?? $employee;

                $workflowRequest = $createFromPreset->handle(
                    requester: $request->user(),
                    companyId: $companyId,
                    document: $document,
                    presetId: (int) $request->validated('workflow_preset_id'),
                    subjectEmployee: $subjectEmployee,
                );
            } else {
                $workflowRequest = $action->handle(
                    requester: $request->user(),
                    companyId: $companyId,
                    document: $document,
                    stages: $request->validated('stages') ?? [],
                );
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (DocumentWorkflowException $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('organization.documents.requests.show', ['workflowRequest' => $workflowRequest->id])
            ->with('success', 'Review and approval request created.');
    }
}
