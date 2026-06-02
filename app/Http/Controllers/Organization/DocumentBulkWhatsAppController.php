<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\BulkWhatsAppDocumentsRequest;
use App\Models\Employee;
use App\Services\DocumentWhatsAppService;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Http\JsonResponse;

class DocumentBulkWhatsAppController extends Controller
{
    public function __invoke(
        BulkWhatsAppDocumentsRequest $request,
        Employee $employee,
        DocumentWhatsAppService $whatsappService,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);

        $result = $whatsappService->sendDocuments(
            employee: $employee,
            documentIds: $request->validated('document_ids'),
            companyId: $companyId,
            sender: $request->user(),
            whatsappNumber: $request->validated('whatsapp_number'),
            sendTemplateFirst: (bool) $request->boolean('send_template_first'),
        );

        $status = $result['failed_count'] === 0 ? 200 : 422;

        return response()->json($result, $status);
    }
}
