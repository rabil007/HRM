<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\StoreDocumentCompanyCountersignRequestRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentCompanyCountersignRequest;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Http\RedirectResponse;

class CreateDocumentCompanyCountersignRequestController extends Controller
{
    public function __invoke(
        StoreDocumentCompanyCountersignRequestRequest $request,
        Employee $employee,
        EmployeeDocument $document,
        CreateDocumentCompanyCountersignRequest $createRequest,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);
        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId, 404);

        $recipientUser = User::query()->findOrFail($request->validated('recipient_user_id'));

        $result = $createRequest->handle(
            $document,
            $recipientUser,
            $request->user(),
            $companyId,
        );

        return back()->with([
            'company_countersign_request_created' => [
                'id' => $result['request']->id,
                'recipient_name' => $result['request']->recipient_name_snapshot,
                'respond_url' => $result['respond_url'],
            ],
        ]);
    }
}
