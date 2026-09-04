<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentTemplateLayoutValidationRun;
use App\Support\Documents\PresentDocumentTemplateLayoutValidationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowDocumentTemplateLayoutValidationRunController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        DocumentTemplateLayoutValidationRun $run,
        PresentDocumentTemplateLayoutValidationRun $present,
    ): JsonResponse {
        abort_unless($request->user()?->can('documents.templates.update') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless((int) $version->document_generation_template_id === (int) $template->id, 404);
        abort_unless((int) $version->company_id === $companyId, 404);
        abort_unless((int) $run->company_id === $companyId, 404);
        abort_unless((int) $run->document_generation_template_id === (int) $template->id, 404);
        abort_unless((int) $run->document_generation_template_version_id === (int) $version->id, 404);

        if ($run->mode === 'employee') {
            abort_unless($request->user()?->can('employees.view') ?? false, 403);
        }

        return response()->json([
            'run' => $present->handle($run),
        ]);
    }
}
