<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Enums\DocumentRecipientAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\StoreDocumentRecipientRequestRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestLinkService;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Http\RedirectResponse;

class CreateDocumentRecipientRequestController extends Controller
{
    public function __invoke(
        StoreDocumentRecipientRequestRequest $request,
        Employee $employee,
        EmployeeDocument $document,
        CreateDocumentRecipientRequest $createRequest,
        DocumentRecipientRequestLinkService $links,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);
        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId, 404);

        $action = DocumentRecipientAction::from($request->validated('action'));

        $result = $createRequest->handle(
            $document,
            $action,
            $request->user(),
            $companyId,
        );

        return back()->with([
            'recipient_request_created' => [
                'id' => $result['request']->id,
                'action' => $action->value,
                'secure_url' => $links->publicUrl($result['raw_token']),
            ],
        ]);
    }
}
