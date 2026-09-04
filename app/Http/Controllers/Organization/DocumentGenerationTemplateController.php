<?php

namespace App\Http\Controllers\Organization;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DocumentGenerationTemplate\ReplaceDocumentGenerationTemplatePdfRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\SaveDocumentGenerationTemplateDesignRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\StoreDocumentGenerationTemplateRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\UpdateDocumentGenerationTemplateRequest;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\BranchDocumentGenerationTemplateDraft;
use App\Support\Documents\Actions\CreateDocumentGenerationTemplate;
use App\Support\Documents\Actions\DeleteDocumentGenerationTemplate;
use App\Support\Documents\Actions\DuplicateDocumentGenerationTemplate;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\QueueDocumentTemplateLayoutValidation;
use App\Support\Documents\Actions\ReplaceDocumentGenerationTemplatePdf;
use App\Support\Documents\Actions\ResolveCurrentDocumentTemplateLayoutValidationRun;
use App\Support\Documents\Actions\SaveDocumentGenerationTemplateDesign;
use App\Support\Documents\Actions\UpdateDocumentGenerationTemplate;
use App\Support\Documents\DocumentGenerationTemplateDesignerOptions;
use App\Support\Documents\DocumentGenerationTemplatePageOptions;
use App\Support\Documents\DocumentGenerationTemplateReadiness;
use App\Support\Documents\DocumentsModuleAccess;
use App\Support\Documents\DocumentTemplateStorage;
use App\Support\Documents\PresentDocumentTemplateLayoutValidationRun;
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
        DocumentGenerationTemplateDesignerOptions $designerOptions,
        ResolveCurrentDocumentTemplateLayoutValidationRun $resolveLayoutRun,
        PresentDocumentTemplateLayoutValidationRun $presentLayoutRun,
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
        $designer = $designerOptions->for($request->user(), $companyId, $template, $initialVersion);

        $layoutValidationRun = $resolveLayoutRun->handle($template, $initialVersion, $companyId);

        return Inertia::render('organization/documents/templates/design', [
            'template' => $template->toBrowseArray(),
            'initial_version' => $initialVersion->toArraySummary(),
            'initial_change_summary' => VersionChangeSummary::compare($previousVersion, $initialVersion),
            'all_versions' => $orderedVersions
                ->map(fn (DocumentGenerationTemplateVersion $version) => $version->toVersionListItem())
                ->values()
                ->toArray(),
            'workflow_presets' => $designer['workflow_presets'],
            'signing_presets' => $designer['signing_presets'],
            'workflow_form_options' => $designer['workflow_form_options'],
            'signing_form_options' => $designer['signing_form_options'],
            'readiness' => $designer['readiness'],
            'layout_validation_run' => $layoutValidationRun !== null
                ? $presentLayoutRun->handle($layoutValidationRun)
                : null,
            ...$pageOptions,
            'can' => array_merge($pageOptions['can'], [
                'create_draft' => DocumentsModuleAccess::canUpdateCustomTemplates($request->user()),
                'update' => DocumentsModuleAccess::canUpdateCustomTemplates($request->user()),
                'preview_employee' => $request->user()?->can('employees.view') ?? false,
                'create_workflow_presets' => $designer['can']['create_workflow_presets'],
                'create_signing_presets' => $designer['can']['create_signing_presets'],
            ]),
        ]);
    }

    public function showVersion(
        Request $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        DocumentGenerationTemplateReadiness $readiness,
        ResolveCurrentDocumentTemplateLayoutValidationRun $resolveLayoutRun,
        PresentDocumentTemplateLayoutValidationRun $presentLayoutRun,
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

        $layoutValidationRun = $resolveLayoutRun->handle($template, $version, $companyId);

        return response()->json([
            'version' => $summary,
            'change_summary' => $changeSummary,
            'readiness' => $readiness->evaluate($version, $template),
            'layout_validation_run' => $layoutValidationRun !== null
                ? $presentLayoutRun->handle($layoutValidationRun)
                : null,
        ]);
    }

    public function saveDesign(
        SaveDocumentGenerationTemplateDesignRequest $request,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        SaveDocumentGenerationTemplateDesign $action,
        DocumentGenerationTemplateReadiness $readiness,
        QueueDocumentTemplateLayoutValidation $queueLayoutValidation,
        PresentDocumentTemplateLayoutValidationRun $presentLayoutRun,
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
            $request->automationBindings(),
        );

        $fresh = $updated->fresh() ?? $updated;
        $layoutValidationRun = $queueLayoutValidation->handle(
            $template,
            $fresh,
            $companyId,
            'sample',
            null,
            null,
            false,
            $request->user()?->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Draft saved.',
            'version' => $fresh->toArraySummary(),
            'readiness' => $readiness->evaluate($fresh, $template),
            'layout_validation_run' => $presentLayoutRun->handle($layoutValidationRun),
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
