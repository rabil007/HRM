<?php

namespace App\Http\Controllers\Organization;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DocumentGenerationTemplate\ReplaceDocumentGenerationTemplatePdfRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\SaveDocumentGenerationTemplateDesignRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\SaveDocumentGenerationTemplatePlacementsRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\SaveDocumentGenerationTemplateSignaturePlacementRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\StoreDocumentGenerationTemplateRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\UpdateDocumentGenerationTemplateRequest;
use App\Http\Requests\Organization\Documents\UpdateDocumentGenerationTemplateAutomationRequest;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\BranchDocumentGenerationTemplateDraft;
use App\Support\Documents\Actions\CreateDocumentGenerationTemplate;
use App\Support\Documents\Actions\DeleteDocumentGenerationTemplate;
use App\Support\Documents\Actions\DuplicateDocumentGenerationTemplate;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\ReplaceDocumentGenerationTemplatePdf;
use App\Support\Documents\Actions\SaveDocumentGenerationTemplateDesign;
use App\Support\Documents\Actions\SaveDocumentGenerationTemplatePlacements;
use App\Support\Documents\Actions\SaveDocumentGenerationTemplateSignaturePlacement;
use App\Support\Documents\Actions\UpdateDocumentGenerationTemplate;
use App\Support\Documents\Actions\UpdateDocumentGenerationTemplateAutomation;
use App\Support\Documents\DocumentGenerationTemplatePageOptions;
use App\Support\Documents\DocumentsModuleAccess;
use App\Support\Documents\DocumentTemplateStorage;
use App\Support\Documents\VersionChangeSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class DocumentGenerationTemplateController extends Controller
{
    public function create(Request $request): RedirectResponse
    {
        abort_unless(DocumentsModuleAccess::canCreateCustomTemplates($request->user()), 403);

        return redirect()->route('organization.documents.templates.create.pdf');
    }

    public function createContent(Request $request): RedirectResponse
    {
        abort_unless(DocumentsModuleAccess::canCreateCustomTemplates($request->user()), 403);

        return redirect()->route('organization.documents.templates.create.pdf');
    }

    public function createPdf(Request $request): InertiaResponse
    {
        abort_unless(DocumentsModuleAccess::canCreateCustomTemplates($request->user()), 403);

        return Inertia::render('organization/documents/templates/create-pdf', [
            ...DocumentGenerationTemplatePageOptions::for($request->user()),
        ]);
    }

    public function edit(Request $request, DocumentGenerationTemplate $template): RedirectResponse
    {
        abort_unless(DocumentsModuleAccess::canUpdateCustomTemplates($request->user()), 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        return redirect()->route('organization.documents.templates');
    }

    public function design(
        Request $request,
        DocumentGenerationTemplate $template,
    ): InertiaResponse {
        abort_unless(DocumentsModuleAccess::canUpdateCustomTemplates($request->user()), 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless($template->isPdfOverlay(), 404);

        $template->load(['versions', 'documentType:id,title', 'publishedVersion', 'draftVersion', 'creator:id,name', 'updater:id,name']);

        $orderedVersions = $template->versions
            ->sortByDesc(fn (DocumentGenerationTemplateVersion $candidate): int => (int) $candidate->version)
            ->values();

        $initialVersion = $template->draftVersion
            ?? $template->publishedVersion
            ?? $orderedVersions->first();

        abort_if($initialVersion === null, 404, 'No versions found for this template.');

        $previousVersion = $orderedVersions->first(
            fn (DocumentGenerationTemplateVersion $candidate): bool => (int) $candidate->version < (int) $initialVersion->version,
        );

        $pageOptions = DocumentGenerationTemplatePageOptions::for($request->user());

        return Inertia::render('organization/documents/templates/design', [
            'template' => $template->toBrowseArray(),
            'initial_version' => $initialVersion->toArraySummary(),
            'initial_change_summary' => VersionChangeSummary::compare($previousVersion, $initialVersion),
            'all_versions' => $orderedVersions
                ->map(fn (DocumentGenerationTemplateVersion $version) => $version->toVersionListItem())
                ->values()
                ->toArray(),
            ...$pageOptions,
            'can' => array_merge($pageOptions['can'], [
                'create_draft' => DocumentsModuleAccess::canUpdateCustomTemplates($request->user()),
                'update' => DocumentsModuleAccess::canUpdateCustomTemplates($request->user()),
                'preview_employee' => $request->user()?->can('employees.view') ?? false,
            ]),
        ]);
    }

    public function showVersion(
        Request $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
    ): JsonResponse {
        abort_unless($request->user()?->can('documents.templates.view') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless((int) $version->document_generation_template_id === (int) $template->id, 404);
        abort_unless((int) $version->company_id === $companyId, 404);

        $previousVersion = $template->versions()
            ->where('version', '<', $version->version)
            ->orderByDesc('version')
            ->first();

        $changeSummary = VersionChangeSummary::compare($previousVersion, $version);

        $summary = $version->toArraySummary();
        unset($summary['source_pdf_path']);

        return response()->json([
            'version' => $summary,
            'change_summary' => $changeSummary,
        ]);
    }

    public function saveDesign(
        SaveDocumentGenerationTemplateDesignRequest $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        SaveDocumentGenerationTemplateDesign $action,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless((int) $version->document_generation_template_id === (int) $template->id, 404);
        abort_unless((int) $version->company_id === $companyId, 404);

        $updated = $action->handle(
            $version,
            $request->placements(),
            $request->signaturePlacementConfig(),
            $request->user()?->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Design saved.',
            'version' => $updated->fresh()->toArraySummary(),
        ]);
    }

    public function store(
        StoreDocumentGenerationTemplateRequest $request,
        CreateDocumentGenerationTemplate $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);

        $template = $action->handle($companyId, array_merge($request->validated(), [
            'file' => $request->file('file'),
        ]), $request->user());

        return redirect()
            ->route('organization.documents.templates.design', $template)
            ->with('success', 'Template created. Place merge fields on the PDF.');
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

        return redirect()
            ->route('organization.documents.templates')
            ->with('success', 'Template updated.');
    }

    public function updateAutomation(
        UpdateDocumentGenerationTemplateAutomationRequest $request,
        DocumentGenerationTemplate $template,
        UpdateDocumentGenerationTemplateAutomation $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $action->handle($template, $request->validated(), $request->user());

        return back()->with('success', 'Template automation updated.');
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
    ): JsonResponse|RedirectResponse {
        abort_unless($request->user()?->can('documents.templates.update') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $draft = $action->handle($template, $request->user()?->id);

        if ($request->wantsJson()) {
            return response()->json([
                'draft' => $draft->toArraySummary(),
                'placement_config' => $draft->placement_config,
                'signature_placement_config' => $draft->signature_placement_config,
                'template' => $template->fresh(['publishedVersion', 'draftVersion'])->toBrowseArray(),
            ]);
        }

        return back()->with('success', 'Draft prepared.');
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
    ): JsonResponse|RedirectResponse {
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

    public function saveSignaturePlacement(
        SaveDocumentGenerationTemplateSignaturePlacementRequest $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        SaveDocumentGenerationTemplateSignaturePlacement $action,
    ): JsonResponse|RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);
        abort_unless((int) $version->document_generation_template_id === (int) $template->id, 404);
        abort_unless((int) $version->company_id === $companyId, 404);

        $updated = $action->handle(
            $version,
            $request->signaturePlacementConfig(),
            $request->user()?->id,
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Signature placement saved.',
                'signature_placement_config' => $updated->signature_placement_config,
                'draft' => $updated->toArraySummary(),
            ]);
        }

        return back()->with('success', 'Signature placement saved.');
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

        return redirect()
            ->route('organization.documents.templates')
            ->with('success', "Version {$version->version} published.");
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

        $publishedVersion = $template->publishedVersion;
        if ($publishedVersion === null
            || (int) $publishedVersion->document_generation_template_id !== (int) $template->id
            || (int) $publishedVersion->company_id !== $companyId
            || ! $publishedVersion->isPublished()
        ) {
            abort(422, 'Cannot activate a template without a valid published version.');
        }

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
        DeleteDocumentGenerationTemplate $action,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.templates.delete') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $action->handle($template);

        return back()->with('success', 'Template deleted.');
    }
}
