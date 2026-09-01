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
    expect($result['signatures_added'])->toContain('company_signatory_1');
    expect($result['signatures_removed'])->toContain('manager_1');
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
        ->and($result['signatures_moved'])->toBe(['manager_1']);
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
