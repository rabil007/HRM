<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
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

        $canAccessDesigner = $canViewCustom || DocumentsModuleAccess::canCreateCustomTemplates($user);

        $canUpdateTemplates = DocumentsModuleAccess::canUpdateCustomTemplates($user);

        return Inertia::render('organization/documents/templates', [
            'custom_templates' => $customTemplates,
            'merge_fields' => $canAccessDesigner ? DocumentTemplateMergeFields::definitions() : [],
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
