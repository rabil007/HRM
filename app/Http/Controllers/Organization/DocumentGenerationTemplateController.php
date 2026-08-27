<?php

namespace App\Http\Controllers\Organization;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DocumentGenerationTemplate\ReplaceDocumentGenerationTemplatePdfRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\SaveDocumentGenerationTemplatePlacementsRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\StoreDocumentGenerationTemplateRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\UpdateDocumentGenerationTemplateRequest;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\BranchDocumentGenerationTemplateDraft;
use App\Support\Documents\Actions\CreateDocumentGenerationTemplate;
use App\Support\Documents\Actions\DuplicateDocumentGenerationTemplate;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\ReplaceDocumentGenerationTemplatePdf;
use App\Support\Documents\Actions\SaveDocumentGenerationTemplatePlacements;
use App\Support\Documents\Actions\UpdateDocumentGenerationTemplate;
use App\Support\Documents\DocumentTemplateStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DocumentGenerationTemplateController extends Controller
{
    public function store(
        StoreDocumentGenerationTemplateRequest $request,
        CreateDocumentGenerationTemplate $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);

        $action->handle($companyId, array_merge($request->validated(), [
            'file' => $request->file('file'),
        ]), $request->user());

        return back()->with('success', 'Template created.');
    }

    public function update(
        UpdateDocumentGenerationTemplateRequest $request,
        DocumentGenerationTemplate $template,
        UpdateDocumentGenerationTemplate $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $action->handle($template, $request->validated(), $request->user());

        return back()->with('success', 'Template updated.');
    }

    public function duplicate(
        Request $request,
        DocumentGenerationTemplate $template,
        DuplicateDocumentGenerationTemplate $action,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.templates.update') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $action->handle($template, $request->user());

        return back()->with('success', 'Template duplicated.');
    }

    public function getOrCreateDraft(
        Request $request,
        DocumentGenerationTemplate $template,
        BranchDocumentGenerationTemplateDraft $action,
    ): JsonResponse {
        abort_unless($request->user()?->can('documents.templates.update') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $draft = $action->handle($template, $request->user()?->id);

        return response()->json([
            'draft' => $draft->toArraySummary(),
            'placement_config' => $draft->placement_config,
            'template' => $template->fresh(['publishedVersion', 'draftVersion'])->toBrowseArray(),
        ]);
    }

    public function sourcePdf(
        Request $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
    ): Response {
        abort_unless($request->user()?->can('documents.templates.view') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless((int) $version->document_generation_template_id === (int) $template->id, 404);
        abort_unless((int) $version->company_id === $companyId, 404);
        abort_unless($template->isPdfOverlay(), 404);

        return DocumentTemplateStorage::response($version, $companyId);
    }

    public function savePlacements(
        SaveDocumentGenerationTemplatePlacementsRequest $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        SaveDocumentGenerationTemplatePlacements $action,
    ): Response {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless((int) $version->document_generation_template_id === (int) $template->id, 404);
        abort_unless((int) $version->company_id === $companyId, 404);

        $action->handle($version, $request->validated()['placements'], $request->user()?->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Placements saved.',
                'placement_config' => $version->fresh()->placement_config,
            ]);
        }

        return back()->with('success', 'Placements saved.');
    }

    public function replacePdf(
        ReplaceDocumentGenerationTemplatePdfRequest $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        ReplaceDocumentGenerationTemplatePdf $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless((int) $version->document_generation_template_id === (int) $template->id, 404);
        abort_unless((int) $version->company_id === $companyId, 404);

        $action->handle($version, $request->file('file'), $request->user()?->id);

        return back()->with('success', 'Template PDF replaced.');
    }

    public function publish(
        Request $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        PublishDocumentGenerationTemplateVersion $action,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.templates.update') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless((int) $version->document_generation_template_id === (int) $template->id, 404);
        abort_unless((int) $version->company_id === $companyId, 404);

        $action->handle($version, $request->user()?->id);

        return back()->with('success', "Version {$version->version} published.");
    }

    public function activate(
        Request $request,
        DocumentGenerationTemplate $template,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.templates.update') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_if($template->published_version_id === null, 422, 'Cannot activate a template with no published version.');

        $template->status = DocumentGenerationTemplateStatus::Active;
        $template->updated_by = $request->user()?->id;
        $template->save();

        return back()->with('success', 'Template activated.');
    }

    public function deactivate(
        Request $request,
        DocumentGenerationTemplate $template,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.templates.update') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $template->status = DocumentGenerationTemplateStatus::Inactive;
        $template->updated_by = $request->user()?->id;
        $template->save();

        return back()->with('success', 'Template deactivated.');
    }

    public function destroy(
        Request $request,
        DocumentGenerationTemplate $template,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.templates.delete') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        // Safely clean up private PDF files for all versions of this template
        foreach ($template->versions as $version) {
            if ($version->source_pdf_path) {
                DocumentTemplateStorage::deletePdf($version->source_pdf_path, $companyId);
            }
        }

        $template->delete();

        return back()->with('success', 'Template deleted.');
    }
}
