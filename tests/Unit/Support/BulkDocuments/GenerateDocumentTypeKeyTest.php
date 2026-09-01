<?php

use App\Support\BulkDocuments\GenerateDocumentTypeKey;

test('generate document type key parses custom template ids', function () {
    expect(GenerateDocumentTypeKey::customTemplateId('custom_12'))->toBe(12)
        ->and(GenerateDocumentTypeKey::isCustom('custom_12'))->toBeTrue()
        ->and(GenerateDocumentTypeKey::customTemplateId('salary_declaration'))->toBeNull()
        ->and(GenerateDocumentTypeKey::isCustom('salary_declaration'))->toBeFalse()
        ->and(GenerateDocumentTypeKey::customTemplateId('custom_0'))->toBeNull()
        ->and(GenerateDocumentTypeKey::customTemplateId('custom_abc'))->toBeNull();
});
