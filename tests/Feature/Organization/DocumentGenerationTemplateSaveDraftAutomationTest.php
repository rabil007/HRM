<?php

use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Enums\DocumentTemplateAutomationMode;
use App\Models\Company;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\User;
use App\Support\Documents\Actions\SaveDocumentGenerationTemplateDesign;
use App\Support\Documents\DocumentTemplateStorage;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake(DocumentTemplateStorage::DISK);
});

/**
 * @return array{user: User, company: Company, template: DocumentGenerationTemplate, version: DocumentGenerationTemplateVersion}
 */
function makeSaveDraftOverlayFixture(): array
{
    $user = User::factory()->create();
    $company = makeDocumentFixtures()['company'];
    grantCompanyPermissions($user, $company, ['documents.templates.update', 'documents.templates.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $path = DocumentTemplateStorage::directory($company->id).'/source.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, '%PDF-1.4');
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => $path,
        'source_pdf_page_count' => 1,
        'placement_config' => ['schema_version' => 2, 'placements' => []],
        'signature_placement_config' => ['schema_version' => 2, 'placements' => []],
    ]);

    return compact('user', 'company', 'template', 'version');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function saveDraftHttpPayload(array $overrides = []): array
{
    return array_merge([
        'placement_config' => [
            'schema_version' => 2,
            'placements' => [[
                'id' => 'p1',
                'type' => 'field',
                'field' => '{{employee_name}}',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.1,
                'width' => 0.3,
                'height' => 0.05,
                'font_size' => 12,
                'font_weight' => 'normal',
                'text_align' => 'left',
            ]],
        ],
        'signature_placement_config' => [
            'schema_version' => 2,
            'placements' => [],
        ],
        'document_workflow_mode' => 'none',
        'document_workflow_preset_id' => null,
        'document_signing_mode' => 'none',
        'document_signing_preset_id' => null,
    ], $overrides);
}

test('save draft atomically persists design and explicit none decisions', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeSaveDraftOverlayFixture();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.design.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), saveDraftHttpPayload())
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Draft saved.')
        ->assertJsonPath('readiness.ready', true);

    $version->refresh();
    expect($version->placement_config['placements'])->toHaveCount(1)
        ->and($version->document_workflow_mode)->toBe(DocumentTemplateAutomationMode::None)
        ->and($version->document_signing_mode)->toBe(DocumentTemplateAutomationMode::None)
        ->and($version->document_workflow_preset_id)->toBeNull()
        ->and($version->document_signing_preset_id)->toBeNull();
});

test('invalid placement rolls back workflow changes', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeSaveDraftOverlayFixture();
    $original = $version->only([
        'placement_config',
        'document_workflow_mode',
        'document_signing_mode',
        'document_workflow_preset_id',
        'document_signing_preset_id',
    ]);

    expect(fn () => app(SaveDocumentGenerationTemplateDesign::class)->handle(
        $version,
        [[
            'id' => 'bad',
            'type' => 'field',
            'field' => '{{employee_name}}',
            'page' => 1,
            'x' => 2,
            'y' => 0.1,
            'width' => 0.3,
            'height' => 0.05,
        ]],
        ['schema_version' => 2, 'placements' => []],
        $user->id,
        [
            'document_workflow_mode' => 'none',
            'document_workflow_preset_id' => null,
            'document_signing_mode' => 'none',
            'document_signing_preset_id' => null,
        ],
    ))->toThrow(ValidationException::class);

    $version->refresh();
    expect($version->placement_config)->toEqual($original['placement_config'])
        ->and($version->document_workflow_mode)->toEqual($original['document_workflow_mode'])
        ->and($version->document_signing_mode)->toEqual($original['document_signing_mode']);
});

test('invalid workflow preset rolls back placement changes', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeSaveDraftOverlayFixture();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.design.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), saveDraftHttpPayload([
            'document_workflow_mode' => 'preset',
            'document_workflow_preset_id' => null,
        ]))
        ->assertUnprocessable();

    $version->refresh();
    expect($version->placement_config['placements'] ?? [])->toBe([])
        ->and($version->document_workflow_mode)->toBeNull();
});

test('invalid signing preset rolls back placement changes', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeSaveDraftOverlayFixture();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.design.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), saveDraftHttpPayload([
            'document_signing_mode' => 'preset',
            'document_signing_preset_id' => null,
        ]))
        ->assertUnprocessable();

    $version->refresh();
    expect($version->placement_config['placements'] ?? [])->toBe([])
        ->and($version->document_signing_mode)->toBeNull();
});

test('cross-company workflow preset is rejected and nothing persists', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeSaveDraftOverlayFixture();
    $foreign = createDocumentWorkflowPresetForCompany(makeDocumentFixtures()['company']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.design.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), saveDraftHttpPayload([
            'document_workflow_mode' => 'preset',
            'document_workflow_preset_id' => $foreign->id,
        ]))
        ->assertUnprocessable();

    $version->refresh();
    expect($version->document_workflow_preset_id)->toBeNull()
        ->and($version->placement_config['placements'] ?? [])->toBe([]);
});

test('cross-company signing preset is rejected', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeSaveDraftOverlayFixture();
    $foreign = createDocumentSigningPresetForCompany(makeDocumentFixtures()['company']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.design.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), saveDraftHttpPayload([
            'document_signing_mode' => 'preset',
            'document_signing_preset_id' => $foreign->id,
        ]))
        ->assertUnprocessable();

    $version->refresh();
    expect($version->document_signing_preset_id)->toBeNull();
});

test('published versions cannot be saved', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeSaveDraftOverlayFixture();
    $version->update([
        'status' => DocumentGenerationTemplateVersionStatus::Published,
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.design.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), saveDraftHttpPayload())
        ->assertUnprocessable();
});

test('archived versions cannot be saved', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeSaveDraftOverlayFixture();
    $version->update([
        'status' => DocumentGenerationTemplateVersionStatus::Archived,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.design.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), saveDraftHttpPayload())
        ->assertUnprocessable();
});
