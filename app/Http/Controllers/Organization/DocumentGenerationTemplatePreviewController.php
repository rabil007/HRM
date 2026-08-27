<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DocumentGenerationTemplate\PreviewDocumentGenerationTemplateRequest;
use App\Models\DocumentGenerationTemplate;
use App\Models\Employee;
use App\Support\Documents\DocumentTemplatePreview;
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

        $employee = null;
        if ($request->filled('employee_id')) {
            $employeeId = (int) $request->query('employee_id');
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->find($employeeId);
        }

        $result = $previewer->renderTemplate($template, $employee, $companyId);

        return response()->json($result);
    }

    public function previewDraft(
        PreviewDocumentGenerationTemplateRequest $request,
        DocumentTemplatePreview $previewer,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);

        $validated = $request->validated();

        $employee = null;
        if (! empty($validated['employee_id'])) {
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->find((int) $validated['employee_id']);
        }

        $result = $previewer->render(
            name: $validated['name'] ?? 'Template Preview',
            content: $validated['content'],
            employee: $employee,
            companyId: $companyId,
        );

        return response()->json($result);
    }
}
