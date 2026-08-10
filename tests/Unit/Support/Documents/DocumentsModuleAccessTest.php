<?php

use App\Support\Documents\DocumentsModuleAccess;

it('resolves visible sections and a default path per permission set', function (array $permissions, array $expectedKeys, ?string $defaultPath) {
    $sections = DocumentsModuleAccess::visibleSections($permissions);

    expect(array_column($sections, 'key'))->toBe($expectedKeys)
        ->and(DocumentsModuleAccess::defaultPath($permissions))->toBe($defaultPath)
        ->and(DocumentsModuleAccess::canAccessModule($permissions))->toBe($defaultPath !== null);
})->with([
    'documents.view only' => [
        ['documents.view'],
        ['overview', 'library', 'templates'],
        '/organization/documents',
    ],
    'bulk_documents.view only' => [
        ['bulk_documents.view'],
        ['generate', 'requests', 'templates', 'activity'],
        '/organization/documents/generate',
    ],
    'bulk_documents.generate only' => [
        ['bulk_documents.generate'],
        [],
        null,
    ],
    'bulk_documents.signatures.review only' => [
        ['bulk_documents.signatures.review'],
        [],
        null,
    ],
    'documents and bulk view' => [
        ['documents.view', 'bulk_documents.view'],
        ['overview', 'library', 'generate', 'requests', 'templates', 'activity'],
        '/organization/documents',
    ],
    'settings.application.view only' => [
        ['settings.application.view'],
        ['templates'],
        '/organization/documents/templates',
    ],
    'settings.application.update only' => [
        ['settings.application.update'],
        [],
        null,
    ],
    'no document permissions' => [
        ['employees.view'],
        [],
        null,
    ],
]);
