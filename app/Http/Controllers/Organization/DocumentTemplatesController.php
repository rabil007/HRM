<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\Documents\DocumentsModuleAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentTemplatesController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless(DocumentsModuleAccess::canAccessSection($user, 'templates'), 403);

        $systemTemplates = collect(BulkDocumentTypeRegistry::definitions())
            ->map(fn (array $definition): array => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'supports_esignature' => $definition['supports_esignature'],
            ])
            ->values()
            ->all();

        return Inertia::render('organization/documents/templates', [
            'section' => 'templates',
            'system_templates' => $systemTemplates,
            'document_types' => $systemTemplates,
            'can' => [
                'configure_placement' => $user?->can('settings.application.view') ?? false,
                'update_placement' => $user?->can('settings.application.update') ?? false,
                'manage_document_types' => $user?->can('settings.master-data.document-types.view') ?? false,
            ],
        ]);
    }
}
