<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\StartDocumentSigningFlowRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Documents\Signing\Actions\StartDocumentSigningFlow;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Http\RedirectResponse;

class StartDocumentSigningFlowController extends Controller
{
    public function __invoke(
        StartDocumentSigningFlowRequest $request,
        Employee $employee,
        EmployeeDocument $document,
        StartDocumentSigningFlow $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);
        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId, 404);

        $result = $action->handle(
            $document,
            $request->user(),
            $companyId,
            (int) $request->validated('document_signing_preset_id'),
        );

        return back()->with([
            'success' => 'Signing flow started.',
            'signing_flow_subject_url' => route('public.document-action.show', [
                'token' => $result['raw_token'],
            ]),
            'signing_flow_id' => $result['flow']->id,
        ]);
    }
}
