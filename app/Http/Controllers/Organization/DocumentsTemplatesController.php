<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\DocumentSigningPreset;
use App\Models\DocumentType;
use App\Models\DocumentWorkflowPreset;
use App\Support\Documents\DocumentsModuleAccess;
use App\Support\Documents\DocumentTemplateMergeFields;
use App\Support\Documents\Queries\DocumentGenerationTemplateQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DocumentsTemplatesController extends Controller
{
    public function __invoke(Request $request): InertiaResponse
    {
        $user = $request->user();

        abort_unless(DocumentsModuleAccess::canViewTemplates($user), 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        $canViewCustom = DocumentsModuleAccess::canViewCustomTemplates($user);

        $customTemplates = $canViewCustom && $companyId > 0
            ? DocumentGenerationTemplateQuery::forCompany($companyId)
            : [];

        $mergeFields = ($canViewCustom || DocumentsModuleAccess::canCreateCustomTemplates($user))
            ? DocumentTemplateMergeFields::definitions()
            : [];

        $documentTypes = ($canViewCustom || DocumentsModuleAccess::canCreateCustomTemplates($user))
            ? DocumentType::query()
                ->where('is_active', true)
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn (DocumentType $type): array => [
                    'id' => $type->id,
                    'title' => $type->title,
                ])
                ->all()
            : [];

        $canUpdateTemplates = DocumentsModuleAccess::canUpdateCustomTemplates($user);
        $workflowPresets = $canUpdateTemplates && $companyId > 0
            ? DocumentWorkflowPreset::query()
                ->forCompany($companyId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (DocumentWorkflowPreset $preset): array => [
                    'id' => $preset->id,
                    'name' => $preset->name,
                ])
                ->all()
            : [];
        $signingPresets = $canUpdateTemplates && $companyId > 0
            ? DocumentSigningPreset::query()
                ->forCompany($companyId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (DocumentSigningPreset $preset): array => [
                    'id' => $preset->id,
                    'name' => $preset->name,
                ])
                ->all()
            : [];

        return Inertia::render('organization/documents/templates', [
            'custom_templates' => $customTemplates,
            'merge_fields' => $mergeFields,
            'document_types' => $documentTypes,
            'workflow_presets' => $workflowPresets,
            'signing_presets' => $signingPresets,
            'system_templates' => DocumentsModuleAccess::systemGenerationTemplates($user),
            'can' => [
                'view_templates' => $canViewCustom,
                'create_templates' => DocumentsModuleAccess::canCreateCustomTemplates($user),
                'update_templates' => $canUpdateTemplates,
                'delete_templates' => DocumentsModuleAccess::canDeleteCustomTemplates($user),
                'document_types' => DocumentsModuleAccess::canViewDocumentTypes($user),
                'signature_placement' => DocumentsModuleAccess::canViewSignaturePlacement($user),
            ],
        ]);
    }
}
