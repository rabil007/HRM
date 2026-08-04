<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentTemplatesController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless(
            $user !== null && (
                $user->can('documents.view')
                || $user->can('bulk_documents.view')
                || $user->can('settings.application.view')
            ),
            403,
        );

        $types = collect(BulkDocumentTypeRegistry::definitions())
            ->map(fn (array $definition): array => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'supports_esignature' => $definition['supports_esignature'],
            ])
            ->values()
            ->all();

        return Inertia::render('organization/documents/templates', [
            'section' => 'templates',
            'document_types' => $types,
            'can' => [
                'configure_placement' => $user->can('settings.application.view'),
                'update_placement' => $user->can('settings.application.update'),
                'manage_document_types' => $user->can('settings.master-data.document-types.view'),
            ],
        ]);
    }
}
