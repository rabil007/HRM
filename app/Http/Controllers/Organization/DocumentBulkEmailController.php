<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\BulkEmailDocumentsRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Services\DocumentEmailService;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentBulkActionService;
use Illuminate\Http\JsonResponse;

class DocumentBulkEmailController extends Controller
{
    public function __invoke(
        BulkEmailDocumentsRequest $request,
        Employee $employee,
        DocumentEmailService $emailService,
        DocumentBulkActionService $bulkActions,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);

        $documents = $bulkActions->documentsForEmployeeAction(
            $request->validated('document_ids'),
            $companyId,
            $employee->id,
        );

        $company = Company::query()->findOrFail($companyId);

        $emailService->send(
            documents: $documents,
            employee: $employee,
            company: $company,
            sender: $request->user(),
            recipient: $request->validated('recipient'),
            ccRecipients: $request->ccRecipients(),
            subject: $request->validated('subject'),
            message: $request->validated('message'),
        );

        return response()->json([
            'message' => 'Email sent successfully.',
        ]);
    }
}
