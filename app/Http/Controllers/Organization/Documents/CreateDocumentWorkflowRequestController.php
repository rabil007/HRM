<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Exceptions\DocumentWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\StoreDocumentWorkflowRequestRequest;
use App\Models\DocumentWorkflowPreset;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Documents\Workflow\Actions\CreateDocumentWorkflowRequest;
use App\Support\Documents\Workflow\ResolveDocumentWorkflowPreset;
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
        ResolveDocumentWorkflowPreset $resolvePreset,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);
        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId, 404);
        DocumentAccess::assertDocumentInCompany($document, $companyId);

        $presetProvenance = [];
        $stages = $request->validated('stages') ?? [];

        if ($request->filled('workflow_preset_id')) {
            $preset = DocumentWorkflowPreset::query()
                ->forCompany($companyId)
                ->whereKey((int) $request->validated('workflow_preset_id'))
                ->firstOrFail();

            $document->loadMissing('employee');
            $subjectEmployee = $document->employee ?? $employee;

            $resolved = $resolvePreset->handle(
                preset: $preset,
                subjectEmployee: $subjectEmployee,
                requester: $request->user(),
                companyId: $companyId,
            );

            $stages = $resolved['stages'];
            $presetProvenance = [
                'preset_id' => $resolved['preset_id'],
                'preset_name' => $resolved['preset_name'],
                'routing_snapshot' => $resolved['routing_snapshot'],
            ];
        }

        try {
            $workflowRequest = $action->handle(
                requester: $request->user(),
                companyId: $companyId,
                document: $document,
                stages: $stages,
                presetProvenance: $presetProvenance,
            );
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
