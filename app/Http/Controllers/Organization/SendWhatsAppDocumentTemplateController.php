<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\SendWhatsAppDocumentTemplateRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppService;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentShareLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SendWhatsAppDocumentTemplateController extends Controller
{
    public function __invoke(
        SendWhatsAppDocumentTemplateRequest $request,
        Employee $employee,
        EmployeeDocument $document,
        DocumentShareLinkService $shareLinks,
        WhatsAppService $whatsapp,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);
        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId, 404);

        if (! WhatsAppSetting::current()->isConfigured()) {
            throw ValidationException::withMessages([
                'whatsapp' => 'WhatsApp integration is not configured or enabled.',
            ]);
        }

        $phone = trim($request->validated('whatsapp_number'));

        $fileName = $shareLinks->displayName($document);
        $documentUrl = $this->publicHttpsUrl($shareLinks->shareUrl($document));

        try {
            $result = $whatsapp->sendDocumentTemplate(
                phone: $phone,
                employeeName: (string) $employee->name,
                documentUrl: $documentUrl,
                fileName: $fileName,
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'message_id' => $result['message_id'],
            'normalized_phone' => $result['normalized_phone'],
        ]);
    }

    private function publicHttpsUrl(string $url): string
    {
        if (str_starts_with($url, 'http://')) {
            return 'https://'.substr($url, 7);
        }

        return $url;
    }
}
