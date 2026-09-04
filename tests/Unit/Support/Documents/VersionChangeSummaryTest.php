<?php

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\VersionChangeSummary;
use Database\Seeders\PermissionsSeeder;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeVersionWithConfig(
    DocumentGenerationTemplate $template,
    int $versionNum,
    string $status,
    array $placementConfig = [],
    array $sigConfig = [],
    array $overrides = [],
): DocumentGenerationTemplateVersion {
    return DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create(array_merge([
        'version' => $versionNum,
        'status' => $status,
        'source_pdf_page_count' => 1,
        'source_pdf_original_name' => 'test.pdf',
        'placement_config' => $placementConfig ?: ['schema_version' => 2, 'placements' => []],
        'signature_placement_config' => $sigConfig ?: ['schema_version' => 2, 'placements' => []],
        'published_at' => $status === 'published' ? now() : null,
    ], $overrides));
}

test('returns null for first version (no previous)', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);
    $v1 = makeVersionWithConfig($template, 1, 'published');

    expect(VersionChangeSummary::compare(null, $v1))->toBeNull();
});

test('detects pdf_metadata_changed when file name differs', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);
    $v1 = makeVersionWithConfig($template, 1, 'archived', overrides: ['source_pdf_original_name' => 'old.pdf']);
    $v2 = makeVersionWithConfig($template, 2, 'published', overrides: ['source_pdf_original_name' => 'new.pdf']);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['pdf_metadata_changed'])->toBeTrue();
});

test('detects fields_added by stable ID', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);

    $v1Config = ['schema_version' => 2, 'placements' => [
        ['id' => 'a', 'type' => 'field', 'field' => '{{employee_name}}', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left'],
    ]];
    $v2Config = ['schema_version' => 2, 'placements' => [
        ['id' => 'a', 'type' => 'field', 'field' => '{{employee_name}}', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left'],
        ['id' => 'b', 'type' => 'field', 'field' => '{{today}}', 'page' => 1, 'x' => 0.5, 'y' => 0.1, 'width' => 0.2, 'height' => 0.05, 'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left'],
    ]];

    $v1 = makeVersionWithConfig($template, 1, 'archived', $v1Config);
    $v2 = makeVersionWithConfig($template, 2, 'published', $v2Config);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['compared_to_version'])->toBe(1);
    expect($result['fields_added'])->toBe(1);
    expect($result['fields_removed'])->toBe(0);
});

test('detects static_text_updated only when text_content changes, not coordinates', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);

    $v1Config = ['schema_version' => 2, 'placements' => [
        ['id' => 'txt1', 'type' => 'text', 'text_content' => 'Hello', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left'],
    ]];
    $v2Config = ['schema_version' => 2, 'placements' => [
        ['id' => 'txt1', 'type' => 'text', 'text_content' => 'World', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left'],
    ]];

    $v1 = makeVersionWithConfig($template, 1, 'archived', $v1Config);
    $v2 = makeVersionWithConfig($template, 2, 'published', $v2Config);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['static_text_updated'])->toBe(1);
    expect($result['static_text_moved'])->toBe(0);
});

test('detects fields_changed when vertical_align changes', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);

    $base = ['id' => 'a', 'type' => 'field', 'field' => '{{employee_name}}', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left'];
    $v1 = makeVersionWithConfig($template, 1, 'archived', ['schema_version' => 2, 'placements' => [$base]]);
    $v2 = makeVersionWithConfig($template, 2, 'published', ['schema_version' => 2, 'placements' => [array_merge($base, ['vertical_align' => 'baseline'])]]);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['fields_changed'])->toBe(1)
        ->and($result['fields_moved'])->toBe(0);
});

test('detects signatures_added and signatures_removed by slot_key', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);

    $v1SigConfig = ['schema_version' => 2, 'placements' => [
        ['id' => 'subject_signature', 'type' => 'signature', 'role' => 'subject', 'slot_key' => 'subject', 'page' => 1, 'x' => 0.1, 'y' => 0.75, 'width' => 0.25, 'height' => 0.08, 'required' => true],
        ['id' => 'manager_signature', 'type' => 'signature', 'role' => 'manager', 'slot_key' => 'manager_1', 'page' => 1, 'x' => 0.5, 'y' => 0.75, 'width' => 0.25, 'height' => 0.08, 'required' => true],
    ]];
    $v2SigConfig = ['schema_version' => 2, 'placements' => [
        ['id' => 'subject_signature', 'type' => 'signature', 'role' => 'subject', 'slot_key' => 'subject', 'page' => 1, 'x' => 0.1, 'y' => 0.75, 'width' => 0.25, 'height' => 0.08, 'required' => true],
        ['id' => 'company_signatory_signature', 'type' => 'signature', 'role' => 'company_signatory', 'slot_key' => 'company_signatory_1', 'page' => 1, 'x' => 0.5, 'y' => 0.75, 'width' => 0.25, 'height' => 0.08, 'required' => true],
    ]];

    $v1 = makeVersionWithConfig($template, 1, 'archived', [], $v1SigConfig);
    $v2 = makeVersionWithConfig($template, 2, 'published', [], $v2SigConfig);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['signatures_added'])->toContain('Company Signatory · company_signatory_signature');
    expect($result['signatures_removed'])->toContain('Department Manager · manager_signature');
});

test('v3 signature diffs compare physical placement ids not slot keys', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);

    $shared = [
        'type' => 'signature',
        'role' => 'subject',
        'slot_key' => 'subject',
        'page' => 1,
        'width' => 0.25,
        'height' => 0.08,
        'required' => true,
    ];
    $v1SigConfig = ['schema_version' => 3, 'placements' => [
        array_merge($shared, ['id' => 'employee_signature_en', 'x' => 0.1, 'y' => 0.75]),
        array_merge($shared, ['id' => 'employee_signature_ar', 'x' => 0.5, 'y' => 0.75]),
    ]];
    $v2SigConfig = ['schema_version' => 3, 'placements' => [
        array_merge($shared, ['id' => 'employee_signature_en', 'x' => 0.1, 'y' => 0.75]),
        array_merge($shared, ['id' => 'employee_signature_ar', 'x' => 0.55, 'y' => 0.7]),
        array_merge($shared, ['id' => 'employee_signature_footer', 'x' => 0.1, 'y' => 0.9]),
    ]];

    $v1 = makeVersionWithConfig($template, 1, 'archived', [], $v1SigConfig);
    $v2 = makeVersionWithConfig($template, 2, 'published', [], $v2SigConfig);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['signatures_added'])->toContain('Employee · employee_signature_footer')
        ->and($result['signatures_removed'])->toBe([])
        ->and($result['signatures_moved'])->toContain('Employee · employee_signature_ar');
});

test('compares legacy v1 signature configs against v2 slot keys without false add or remove', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);

    $v1SigConfig = ['schema_version' => 1, 'placements' => [
        ['id' => 'subject_signature', 'type' => 'signature', 'role' => 'subject', 'page' => 1, 'x' => 0.1, 'y' => 0.75, 'width' => 0.25, 'height' => 0.08, 'required' => true],
        ['id' => 'manager_signature', 'type' => 'signature', 'role' => 'manager', 'page' => 1, 'x' => 0.5, 'y' => 0.75, 'width' => 0.25, 'height' => 0.08, 'required' => true],
        ['id' => 'company_signatory_signature', 'type' => 'signature', 'role' => 'company_signatory', 'page' => 1, 'x' => 0.75, 'y' => 0.75, 'width' => 0.2, 'height' => 0.08, 'required' => true],
    ]];
    $v2SigConfig = ['schema_version' => 2, 'placements' => [
        ['id' => 'subject_signature', 'type' => 'signature', 'role' => 'subject', 'slot_key' => 'subject', 'page' => 1, 'x' => 0.1, 'y' => 0.75, 'width' => 0.25, 'height' => 0.08, 'required' => true],
        ['id' => 'manager_signature', 'type' => 'signature', 'role' => 'manager', 'slot_key' => 'manager_1', 'page' => 1, 'x' => 0.5, 'y' => 0.75, 'width' => 0.25, 'height' => 0.08, 'required' => true],
        ['id' => 'company_signatory_signature', 'type' => 'signature', 'role' => 'company_signatory', 'slot_key' => 'company_signatory_1', 'page' => 1, 'x' => 0.75, 'y' => 0.75, 'width' => 0.2, 'height' => 0.08, 'required' => true],
    ]];

    $v1 = makeVersionWithConfig($template, 1, 'archived', [], $v1SigConfig);
    $v2 = makeVersionWithConfig($template, 2, 'published', [], $v2SigConfig);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['signatures_added'])->toBe([])
        ->and($result['signatures_removed'])->toBe([])
        ->and($result['signatures_moved'])->toBe([]);
});

test('reports moved when a legacy v1 signature slot changes coordinates in v2', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);

    $v1SigConfig = ['schema_version' => 1, 'placements' => [
        ['id' => 'manager_signature', 'type' => 'signature', 'role' => 'manager', 'page' => 1, 'x' => 0.5, 'y' => 0.75, 'width' => 0.25, 'height' => 0.08, 'required' => true],
    ]];
    $v2SigConfig = ['schema_version' => 2, 'placements' => [
        ['id' => 'manager_signature', 'type' => 'signature', 'role' => 'manager', 'slot_key' => 'manager_1', 'page' => 1, 'x' => 0.2, 'y' => 0.75, 'width' => 0.25, 'height' => 0.08, 'required' => true],
    ]];

    $v1 = makeVersionWithConfig($template, 1, 'archived', [], $v1SigConfig);
    $v2 = makeVersionWithConfig($template, 2, 'published', [], $v2SigConfig);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['signatures_added'])->toBe([])
        ->and($result['signatures_removed'])->toBe([])
        ->and($result['signatures_moved'])->toBe(['Department Manager · manager_signature']);
});

test('reports moved when a signature alignment changes', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);

    $shared = [
        'id' => 'subject_signature',
        'type' => 'signature',
        'role' => 'subject',
        'slot_key' => 'subject',
        'page' => 1,
        'x' => 0.1,
        'y' => 0.75,
        'width' => 0.25,
        'height' => 0.08,
        'required' => true,
    ];

    $v1 = makeVersionWithConfig($template, 1, 'archived', [], [
        'schema_version' => 3,
        'placements' => [$shared],
    ]);
    $v2 = makeVersionWithConfig($template, 2, 'published', [], [
        'schema_version' => 3,
        'placements' => [array_merge($shared, [
            'text_align' => 'right',
            'vertical_align' => 'top',
        ])],
    ]);

    $result = VersionChangeSummary::compare($v1, $v2);

    expect($result['signatures_moved'])->toBe(['Employee · subject_signature']);
});

test('does NOT report moved from count changes alone', function () {
    $template = DocumentGenerationTemplate::factory()->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);

    $v1Config = ['schema_version' => 2, 'placements' => [
        ['id' => 'a', 'type' => 'field', 'field' => '{{employee_name}}', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left'],
        ['id' => 'b', 'type' => 'field', 'field' => '{{today}}', 'page' => 1, 'x' => 0.5, 'y' => 0.1, 'width' => 0.2, 'height' => 0.05, 'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left'],
    ]];
    $v2Config = ['schema_version' => 2, 'placements' => [
        ['id' => 'a', 'type' => 'field', 'field' => '{{employee_name}}', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left'],
    ]];

    $v1 = makeVersionWithConfig($template, 1, 'archived', $v1Config);
    $v2 = makeVersionWithConfig($template, 2, 'published', $v2Config);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['fields_removed'])->toBe(1);
    expect($result['fields_moved'])->toBe(0);
});

test('legacy null mode with the same preset id is not a false automation change', function () {
    $company = makeDocumentFixtures()['company'];
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);
    $workflowPreset = createDocumentWorkflowPresetForCompany($company);
    $signingPreset = createDocumentSigningPresetForCompany($company);

    $v1 = makeVersionWithConfig($template, 1, 'archived', overrides: [
        'document_workflow_mode' => null,
        'document_workflow_preset_id' => $workflowPreset->id,
        'document_signing_mode' => null,
        'document_signing_preset_id' => $signingPreset->id,
    ]);
    $v2 = makeVersionWithConfig($template, 2, 'published', overrides: [
        'document_workflow_mode' => 'preset',
        'document_workflow_preset_id' => $workflowPreset->id,
        'document_signing_mode' => 'preset',
        'document_signing_preset_id' => $signingPreset->id,
    ]);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['workflow_preset_changed'])->toBeFalse()
        ->and($result['signing_preset_changed'])->toBeFalse();
});

test('workflow mode none to preset is reported as a review change', function () {
    $company = makeDocumentFixtures()['company'];
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);
    $workflowPreset = createDocumentWorkflowPresetForCompany($company);

    $v1 = makeVersionWithConfig($template, 1, 'archived', overrides: [
        'document_workflow_mode' => 'none',
        'document_workflow_preset_id' => null,
    ]);
    $v2 = makeVersionWithConfig($template, 2, 'published', overrides: [
        'document_workflow_mode' => 'preset',
        'document_workflow_preset_id' => $workflowPreset->id,
    ]);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['workflow_preset_changed'])->toBeTrue();
});

test('signing preset id change is reported', function () {
    $company = makeDocumentFixtures()['company'];
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create(['template_format' => 'pdf_overlay', 'status' => 'draft']);
    $signingA = createDocumentSigningPresetForCompany($company, null, 'Signing A');
    $signingB = createDocumentSigningPresetForCompany($company, null, 'Signing B');

    $v1 = makeVersionWithConfig($template, 1, 'archived', overrides: [
        'document_signing_mode' => 'preset',
        'document_signing_preset_id' => $signingA->id,
    ]);
    $v2 = makeVersionWithConfig($template, 2, 'published', overrides: [
        'document_signing_mode' => 'preset',
        'document_signing_preset_id' => $signingB->id,
    ]);

    $result = VersionChangeSummary::compare($v1, $v2);
    expect($result['signing_preset_changed'])->toBeTrue();
});
