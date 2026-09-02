<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Enums\DocumentTemplateAutomationMode;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\User;
use App\Support\Documents\Actions\BranchDocumentGenerationTemplateDraft;
use App\Support\Documents\DocumentTemplateAutomationBindings;
use App\Support\Documents\DocumentTemplateStorage;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake(DocumentTemplateStorage::DISK);
});

test('automation mode columns are nullable on new overlay drafts', function () {
    $user = User::factory()->create();
    $company = makeDocumentFixtures()['company'];
    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    $fpdi = new Fpdi;
    $fpdi->AddPage();
    $pdf = UploadedFile::fake()->createWithContent('offer.pdf', $fpdi->Output('S'));

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
            'name' => 'Unconfigured Offer',
            'file' => $pdf,
        ])
        ->assertRedirect();

    $draft = DocumentGenerationTemplate::query()
        ->where('company_id', $company->id)
        ->where('name', 'Unconfigured Offer')
        ->first()
        ?->draftVersion;

    expect($draft)->not->toBeNull()
        ->and($draft->document_workflow_mode)->toBeNull()
        ->and($draft->document_signing_mode)->toBeNull()
        ->and($draft->document_workflow_preset_id)->toBeNull()
        ->and($draft->document_signing_preset_id)->toBeNull()
        ->and($draft->effectiveWorkflowMode())->toBeNull()
        ->and($draft->effectiveSigningMode())->toBeNull();
});

test('legacy preset id with null mode reads as preset without rewriting the row', function () {
    $company = makeDocumentFixtures()['company'];
    $preset = createDocumentWorkflowPresetForCompany($company);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'document_workflow_mode' => null,
        'document_workflow_preset_id' => $preset->id,
        'document_signing_mode' => null,
        'document_signing_preset_id' => null,
    ]);

    expect(DocumentTemplateAutomationBindings::effectiveMode(null, $preset->id))
        ->toBe(DocumentTemplateAutomationMode::Preset)
        ->and($version->effectiveWorkflowMode())->toBe(DocumentTemplateAutomationMode::Preset)
        ->and($version->effectiveSigningMode())->toBeNull();

    $version->refresh();
    expect($version->document_workflow_mode)->toBeNull()
        ->and($version->document_workflow_preset_id)->toBe($preset->id);
});

test('legacy null id and null mode stays unconfigured', function () {
    expect(DocumentTemplateAutomationBindings::effectiveMode(null, null))->toBeNull();
});

test('branching from a legacy configured version normalizes only the new draft', function () {
    $user = User::factory()->create();
    $company = makeDocumentFixtures()['company'];
    $preset = createDocumentWorkflowPresetForCompany($company);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->active()->create();

    $path = DocumentTemplateStorage::directory($company->id).'/legacy.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, '%PDF-1.4');

    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $path,
        'source_pdf_page_count' => 1,
        'document_workflow_mode' => null,
        'document_workflow_preset_id' => $preset->id,
        'document_signing_mode' => null,
        'document_signing_preset_id' => null,
    ]);
    $template->update(['published_version_id' => $published->id]);

    $draft = app(BranchDocumentGenerationTemplateDraft::class)->handle($template, $user->id);

    expect($draft->id)->not->toBe($published->id)
        ->and($draft->document_workflow_mode)->toBe(DocumentTemplateAutomationMode::Preset)
        ->and($draft->document_workflow_preset_id)->toBe($preset->id)
        ->and($draft->document_signing_mode)->toBeNull()
        ->and($draft->document_signing_preset_id)->toBeNull();

    $published->refresh();
    expect($published->document_workflow_mode)->toBeNull()
        ->and($published->document_workflow_preset_id)->toBe($preset->id)
        ->and($published->status)->toBe(DocumentGenerationTemplateVersionStatus::Published);
});

test('branching from a fully unconfigured version leaves the new draft unconfigured', function () {
    $user = User::factory()->create();
    $company = makeDocumentFixtures()['company'];
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->active()->create();

    $path = DocumentTemplateStorage::directory($company->id).'/plain.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, '%PDF-1.4');

    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $path,
        'source_pdf_page_count' => 1,
        'document_workflow_mode' => null,
        'document_signing_mode' => null,
    ]);
    $template->update(['published_version_id' => $published->id]);

    $draft = app(BranchDocumentGenerationTemplateDraft::class)->handle($template, $user->id);

    expect($draft->document_workflow_mode)->toBeNull()
        ->and($draft->document_signing_mode)->toBeNull()
        ->and($draft->document_workflow_preset_id)->toBeNull()
        ->and($draft->document_signing_preset_id)->toBeNull();
});

test('template versions stay owned by their company after branching', function () {
    $company = makeDocumentFixtures()['company'];
    $other = makeDocumentFixtures()['company'];
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->active()->create();
    $path = DocumentTemplateStorage::directory($company->id).'/owned.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, '%PDF-1.4');
    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'source_pdf_path' => $path,
        'source_pdf_page_count' => 1,
    ]);
    $template->update(['published_version_id' => $published->id]);

    $draft = app(BranchDocumentGenerationTemplateDraft::class)->handle($template);

    expect($draft->company_id)->toBe($company->id)
        ->and($draft->document_generation_template_id)->toBe($template->id)
        ->and(DocumentGenerationTemplateVersion::query()->where('company_id', $other->id)->count())->toBe(0);
});
