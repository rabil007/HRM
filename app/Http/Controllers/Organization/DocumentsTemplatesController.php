<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Documents\DocumentsModuleAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DocumentsTemplatesController extends Controller
{
    public function __invoke(Request $request): InertiaResponse
    {
        $user = $request->user();

        abort_unless(DocumentsModuleAccess::canViewTemplates($user), 403);

        return Inertia::render('organization/documents/templates', [
            'system_templates' => DocumentsModuleAccess::systemGenerationTemplates($user),
            'can' => [
                'document_types' => DocumentsModuleAccess::canViewDocumentTypes($user),
                'signature_placement' => DocumentsModuleAccess::canViewSignaturePlacement($user),
            ],
        ]);
    }
}
