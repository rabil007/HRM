<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DocumentGenerationTemplate\ValidateDocumentGenerationTemplateDesignRequest;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\ValidateDocumentGenerationTemplateDesign;
use App\Support\Documents\PresentDocumentTemplateLayoutValidationRun;
use Illuminate\Http\JsonResponse;

class ValidateDocumentGenerationTemplateDesignController extends Controller
{
    public function __invoke(
        ValidateDocumentGenerationTemplateDesignRequest $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        ValidateDocumentGenerationTemplateDesign $action,
        PresentDocumentTemplateLayoutValidationRun $present,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless((int) $version->document_generation_template_id === (int) $template->id, 404);
        abort_unless((int) $version->company_id === $companyId, 404);
        abort_unless($template->isPdfOverlay(), 404);

        $user = $request->user();
        $canPreviewEmployee = ($user?->can('documents.templates.update') ?? false)
            && ($user?->can('employees.view') ?? false);

        $run = $action->handle(
            $template,
            $version,
            $companyId,
            $request->mode(),
            $request->placementConfig(),
            $request->employeeId(),
            $canPreviewEmployee,
            $user?->id,
        );

        $payload = ['run' => $present->handle($run)];
        $status = $run->status->isActive() ? 202 : 200;

        return response()->json($payload, $status);
    }
}
