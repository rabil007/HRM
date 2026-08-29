<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentManagerCountersignRequest;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CreateDocumentManagerCountersignRequestController extends Controller
{
    public function __invoke(
        Request $request,
        Employee $employee,
        EmployeeDocument $document,
        CreateDocumentManagerCountersignRequest $createRequest,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.recipient-requests.create'), 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);
        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId, 404);

        $result = $createRequest->handle(
            $document,
            $request->user(),
            $companyId,
        );

        return back()->with([
            'manager_countersign_request_created' => [
                'id' => $result['request']->id,
                'recipient_name' => $result['manager_name'],
                'respond_url' => $result['respond_url'],
            ],
        ]);
    }
}
