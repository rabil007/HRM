<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
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

        return Inertia::render('organization/documents/templates', [
            'custom_templates' => $customTemplates,
            'merge_fields' => $mergeFields,
            'document_types' => $documentTypes,
            'system_templates' => DocumentsModuleAccess::systemGenerationTemplates($user),
            'can' => [
                'view_templates' => $canViewCustom,
                'create_templates' => DocumentsModuleAccess::canCreateCustomTemplates($user),
                'update_templates' => $canUpdateTemplates,
                'delete_templates' => DocumentsModuleAccess::canDeleteCustomTemplates($user),
                'document_types' => DocumentsModuleAccess::canViewDocumentTypes($user),
                'generate' => $user?->can('bulk_documents.generate') ?? false,
            ],
        ]);
    }
}
