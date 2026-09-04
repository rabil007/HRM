<?php

use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Enums\DocumentTemplateAutomationMode;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\User;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\DocumentGenerationTemplateReadiness;
use App\Support\Documents\DocumentTemplateStorage;
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake(DocumentTemplateStorage::DISK);
});

/**
 * @return array{user: User, company: mixed, template: DocumentGenerationTemplate, version: DocumentGenerationTemplateVersion}
 */
function makeReadinessOverlayDraft(): array
{
    $user = User::factory()->create();
    $company = makeDocumentFixtures()['company'];
    grantCompanyPermissions($user, $company, ['documents.templates.update', 'documents.templates.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $path = DocumentTemplateStorage::directory($company->id).'/source.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, minimalPdfBytes());
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

function readinessCodes(array $result): array
{
    return collect($result['issues'])->pluck('code')->all();
}

test('workflow mode null is a blocking issue', function () {
    ['template' => $template, 'version' => $version] = makeReadinessOverlayDraft();

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version, $template);

    expect($result['ready'])->toBeFalse()
        ->and(readinessCodes($result))->toContain(DocumentGenerationTemplateReadiness::CODE_WORKFLOW_DECISION_MISSING)
        ->and(readinessCodes($result))->toContain(DocumentGenerationTemplateReadiness::CODE_SIGNING_DECISION_MISSING);
});

test('workflow none with null id is valid', function () {
    ['template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['ready'])->toBeTrue()
        ->and($result['blocking_count'])->toBe(0);
});

test('workflow preset with valid company preset is valid', function () {
    ['company' => $company, 'template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $preset = createDocumentWorkflowPresetForCompany($company);
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::Preset,
        'document_workflow_preset_id' => $preset->id,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['ready'])->toBeTrue();
});

test('workflow preset with null id is blocking', function () {
    ['template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::Preset,
        'document_workflow_preset_id' => null,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['ready'])->toBeFalse()
        ->and(readinessCodes($result))->toContain(DocumentGenerationTemplateReadiness::CODE_WORKFLOW_PRESET_MISSING);
});

test('workflow none with preset id is invalid', function () {
    ['company' => $company, 'template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $preset = createDocumentWorkflowPresetForCompany($company);
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_workflow_preset_id' => $preset->id,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['ready'])->toBeFalse()
        ->and(readinessCodes($result))->toContain(DocumentGenerationTemplateReadiness::CODE_WORKFLOW_PRESET_CONFLICT);
});

test('signing none with leftover signature slots is blocking', function () {
    ['template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
        'signature_placement_config' => [
            'schema_version' => 2,
            'placements' => [[
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
            ]],
        ],
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['ready'])->toBeFalse()
        ->and(readinessCodes($result))->toContain(DocumentGenerationTemplateReadiness::CODE_SIGNING_PLACEMENTS_CONFLICT);
});

test('signing preset with missing required placement is blocking', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $user,
        $company->id,
        'Employee signing',
        null,
        [['recipient_role' => 'subject']],
    );
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::Preset,
        'document_signing_preset_id' => $preset->id,
        'signature_placement_config' => ['schema_version' => 2, 'placements' => []],
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['ready'])->toBeFalse()
        ->and(readinessCodes($result))->toContain(DocumentGenerationTemplateReadiness::CODE_SIGNING_PLACEMENT_MISSING);
});

test('signing preset with complete required placements is valid', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $user,
        $company->id,
        'Employee signing',
        null,
        [['recipient_role' => 'subject']],
    );
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::Preset,
        'document_signing_preset_id' => $preset->id,
        'signature_placement_config' => [
            'schema_version' => 2,
            'placements' => [[
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
            ]],
        ],
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['ready'])->toBeTrue();
});

test('multiple physical placements for one slot satisfy one signing obligation', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $user,
        $company->id,
        'Employee signing',
        null,
        [['recipient_role' => 'subject']],
    );
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::Preset,
        'document_signing_preset_id' => $preset->id,
        'signature_placement_config' => [
            'schema_version' => 3,
            'placements' => [
                [
                    'id' => 'employee_signature_en',
                    'type' => 'signature',
                    'role' => 'subject',
                    'slot_key' => 'subject',
                    'page' => 1,
                    'x' => 0.1,
                    'y' => 0.75,
                    'width' => 0.25,
                    'height' => 0.08,
                    'required' => true,
                ],
                [
                    'id' => 'employee_signature_ar',
                    'type' => 'signature',
                    'role' => 'subject',
                    'slot_key' => 'subject',
                    'page' => 1,
                    'x' => 0.5,
                    'y' => 0.75,
                    'width' => 0.25,
                    'height' => 0.08,
                    'required' => true,
                ],
            ],
        ],
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['ready'])->toBeTrue()
        ->and(readinessCodes($result))->not->toContain(DocumentGenerationTemplateReadiness::CODE_SIGNING_PLACEMENT_MISSING);
});

test('cross-company workflow preset is invalid', function () {
    ['template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $foreign = createDocumentWorkflowPresetForCompany(makeDocumentFixtures()['company']);
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::Preset,
        'document_workflow_preset_id' => $foreign->id,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['ready'])->toBeFalse()
        ->and(readinessCodes($result))->toContain(DocumentGenerationTemplateReadiness::CODE_WORKFLOW_PRESET_UNAVAILABLE);
});

test('inactive workflow preset is blocking', function () {
    ['company' => $company, 'template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $preset = createDocumentWorkflowPresetForCompany($company, null, 'Inactive review', false);
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::Preset,
        'document_workflow_preset_id' => $preset->id,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['ready'])->toBeFalse()
        ->and(readinessCodes($result))->toContain(DocumentGenerationTemplateReadiness::CODE_WORKFLOW_PRESET_INACTIVE);
});

test('historical versions with null modes are informational not blocking', function () {
    ['template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $version->update([
        'status' => DocumentGenerationTemplateVersionStatus::Published,
        'published_at' => now(),
        'document_workflow_mode' => null,
        'document_signing_mode' => null,
    ]);

    $result = app(DocumentGenerationTemplateReadiness::class)->evaluate($version->fresh(), $template);

    expect($result['historical'])->toBeTrue()
        ->and($result['blocking_count'])->toBe(0)
        ->and($result['ready'])->toBeFalse()
        ->and(readinessCodes($result))->toContain(DocumentGenerationTemplateReadiness::CODE_LEGACY_WORKFLOW_UNCONFIGURED)
        ->and(readinessCodes($result))->toContain(DocumentGenerationTemplateReadiness::CODE_LEGACY_SIGNING_UNCONFIGURED);
});

test('incomplete workflow decisions cannot publish via HTTP', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeReadinessOverlayDraft();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.versions.publish', [
            'template' => $template->id,
            'version' => $version->id,
        ]))
        ->assertUnprocessable();

    expect($version->fresh()->isDraft())->toBeTrue();
});

test('draft with both none decisions can publish', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
    ]);

    seedAuthoritativeValidLayoutRun($template, $version->fresh());

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.versions.publish', [
            'template' => $template->id,
            'version' => $version->id,
        ]))
        ->assertRedirect();

    expect($version->fresh()->isPublished())->toBeTrue();
});

test('draft with workflow preset and signing none can publish', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $preset = createDocumentWorkflowPresetForCompany($company);
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::Preset,
        'document_workflow_preset_id' => $preset->id,
        'document_signing_mode' => DocumentTemplateAutomationMode::None,
    ]);

    seedAuthoritativeValidLayoutRun($template, $version->fresh());
    app(PublishDocumentGenerationTemplateVersion::class)->handle($version->fresh(), $user->id);

    expect($version->fresh()->isPublished())->toBeTrue();
});

test('draft with both valid preset configurations can publish', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makeReadinessOverlayDraft();
    $workflowPreset = createDocumentWorkflowPresetForCompany($company);
    $signingPreset = app(StoreDocumentSigningPreset::class)->handle(
        $user,
        $company->id,
        'Employee signing',
        null,
        [['recipient_role' => 'subject']],
    );
    $version->update([
        'document_workflow_mode' => DocumentTemplateAutomationMode::Preset,
        'document_workflow_preset_id' => $workflowPreset->id,
        'document_signing_mode' => DocumentTemplateAutomationMode::Preset,
        'document_signing_preset_id' => $signingPreset->id,
        'signature_placement_config' => [
            'schema_version' => 2,
            'placements' => [[
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
            ]],
        ],
    ]);

    seedAuthoritativeValidLayoutRun($template, $version->fresh());
    app(PublishDocumentGenerationTemplateVersion::class)->handle($version->fresh(), $user->id);

    expect($version->fresh()->isPublished())->toBeTrue();
});

test('evaluateForPublish throws when workflow is unconfigured', function () {
    ['template' => $template, 'version' => $version] = makeReadinessOverlayDraft();

    expect(fn () => app(DocumentGenerationTemplateReadiness::class)->evaluateForPublish($version, $template))
        ->toThrow(ValidationException::class);
});
