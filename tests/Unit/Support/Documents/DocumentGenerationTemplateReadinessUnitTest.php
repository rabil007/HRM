<?php

use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Enums\DocumentTemplateAutomationMode;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\DocumentGenerationTemplateReadiness;
use App\Support\Documents\DocumentTemplateStorage;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake(DocumentTemplateStorage::DISK);
});

test('signing mode null is a blocking issue on drafts', function () {
    $template = DocumentGenerationTemplate::factory()->pdfOverlay()->create();
    $path = DocumentTemplateStorage::directory((int) $template->company_id).'/source.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, '%PDF-1.4');
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'source_pdf_path' => $path,
        'source_pdf_page_count' => 1,
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => null,
        'placement_config' => ['schema_version' => 2, 'placements' => []],
        'signature_placement_config' => ['schema_version' => 2, 'placements' => []],
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version, $template);

    expect(collect($result['issues'])->pluck('code'))
        ->toContain(DocumentGenerationTemplateReadiness::CODE_SIGNING_DECISION_MISSING)
        ->and($result['ready'])->toBeFalse();
});

test('signing mode none without placements is valid', function () {
    $template = DocumentGenerationTemplate::factory()->pdfOverlay()->create();
    $path = DocumentTemplateStorage::directory((int) $template->company_id).'/source.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, '%PDF-1.4');
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'source_pdf_path' => $path,
        'source_pdf_page_count' => 1,
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
        'placement_config' => ['schema_version' => 2, 'placements' => []],
        'signature_placement_config' => ['schema_version' => 2, 'placements' => []],
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version, $template);

    expect($result['ready'])->toBeTrue();
});

test('signing preset without a preset id is blocking', function () {
    $template = DocumentGenerationTemplate::factory()->pdfOverlay()->create();
    $path = DocumentTemplateStorage::directory((int) $template->company_id).'/source.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, '%PDF-1.4');
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'source_pdf_path' => $path,
        'source_pdf_page_count' => 1,
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::Preset,
        'document_signing_preset_id' => null,
        'placement_config' => ['schema_version' => 2, 'placements' => []],
        'signature_placement_config' => ['schema_version' => 2, 'placements' => []],
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version, $template);

    expect(collect($result['issues'])->pluck('code'))
        ->toContain(DocumentGenerationTemplateReadiness::CODE_SIGNING_PRESET_MISSING);
});

test('published historical versions are not mutated by evaluation', function () {
    $template = DocumentGenerationTemplate::factory()->pdfOverlay()->create();
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'document_workflow_mode' => null,
        'document_signing_mode' => null,
        'status' => DocumentGenerationTemplateVersionStatus::Published,
    ]);
    $original = $version->only(['document_workflow_mode', 'document_signing_mode', 'updated_at']);

    app(DocumentGenerationTemplateReadiness::class)->evaluate($version, $template);

    $version->refresh();
    expect($version->document_workflow_mode)->toEqual($original['document_workflow_mode'])
        ->and($version->document_signing_mode)->toEqual($original['document_signing_mode']);
});
