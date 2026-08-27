<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DocumentGenerationTemplate\PreviewDocumentGenerationTemplateRequest;
use App\Models\DocumentGenerationTemplate;
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
}
