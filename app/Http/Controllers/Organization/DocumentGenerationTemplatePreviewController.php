<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DocumentGenerationTemplate\PreviewDocumentGenerationTemplateRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\SearchDesignEmployeesRequest;
use App\Models\DocumentGenerationTemplate;
use App\Models\Employee;
use App\Support\Documents\DocumentTemplatePreview;
use App\Support\Documents\TemplateDesignEmployeePreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentGenerationTemplatePreviewController extends Controller
{
    public function preview(
        Request $request,
        DocumentGenerationTemplate $template,
        DocumentTemplatePreview $previewer,
    ): JsonResponse {
        abort_unless($request->user()?->can('documents.templates.view') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $result = $previewer->renderTemplate($template, $companyId);

        return response()->json($result);
    }

    public function previewDraft(
        PreviewDocumentGenerationTemplateRequest $request,
        DocumentTemplatePreview $previewer,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);

        $validated = $request->validated();

        $result = $previewer->render(
            name: $validated['name'] ?? 'Template Preview',
            content: $validated['content'],
            companyId: $companyId,
        );

        return response()->json($result);
    }

    public function searchEmployees(
        SearchDesignEmployeesRequest $request,
        DocumentGenerationTemplate $template,
    ): JsonResponse {
        $companyId = $this->authorizedDesignCompanyId($request, $template);

        $validated = $request->validated();

        return response()->json([
            'employees' => TemplateDesignEmployeePreview::search(
                $companyId,
                (string) ($validated['q'] ?? ''),
            ),
        ]);
    }

    public function employeeValues(
        Request $request,
        DocumentGenerationTemplate $template,
        Employee $employee,
    ): JsonResponse {
        abort_unless($request->user()?->can('documents.templates.update') ?? false, 403);
        abort_unless($request->user()?->can('employees.view') ?? false, 403);

        $companyId = $this->authorizedDesignCompanyId($request, $template);
        abort_unless((int) $employee->company_id === $companyId, 404);
        abort_unless($employee->status === 'active', 404);

        return response()->json(
            TemplateDesignEmployeePreview::valuesForCompanyEmployee($companyId, $employee),
        );
    }

    private function authorizedDesignCompanyId(
        Request $request,
        DocumentGenerationTemplate $template,
    ): int {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless($template->isPdfOverlay(), 404);

        return $companyId;
    }
}
