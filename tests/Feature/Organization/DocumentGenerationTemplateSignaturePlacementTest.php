<?php

use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Enums\DocumentTemplateAutomationMode;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Actions\BranchDocumentGenerationTemplateDraft;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\SaveDocumentGenerationTemplateSignaturePlacement;
use App\Support\Documents\DocumentTemplateStorage;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEligibility;
use App\Support\Documents\RecipientRequests\ResolveDocumentSignaturePlacement;
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

function createSignaturePlacementTestCompany(string $name = 'Sig Placement Co'): Company
{
    $code = strtoupper((string) fake()->unique()->lexify('??'));
    $country = Country::query()->firstOrCreate(
        ['code' => $code],
        ['name' => "Test {$code}", 'dial_code' => '+999', 'is_active' => true],
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => $code],
        ['name' => "Test {$code}", 'symbol' => '$', 'is_active' => true],
    );

    return Company::query()->create([
        'name' => $name,
        'slug' => strtolower($code).'-'.fake()->unique()->numberBetween(1000, 9999),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

/**
 * @return array{schema_version: int, placements: list<array<string, mixed>>}
 */
function validSubjectSignaturePlacement(int $page = 1): array
{
    return [
        'schema_version' => 1,
        'placements' => [[
            'id' => 'subject_signature',
            'type' => 'signature',
            'role' => 'subject',
            'page' => $page,
            'x' => 0.12,
            'y' => 0.72,
            'width' => 0.28,
            'height' => 0.09,
            'required' => true,
        ]],
    ];
}

/**
 * @return array{user: User, company: Company, template: DocumentGenerationTemplate, version: DocumentGenerationTemplateVersion}
 */

/**
 * @param  array{schema_version: int, placements: list<array<string, mixed>>}  $signaturePlacementConfig
 * @param  list<array<string, mixed>>  $placements
 */
function putUnifiedDesignerDraft(
    User $user,
    Company $company,
    DocumentGenerationTemplate $template,
    DocumentGenerationTemplateVersion $version,
    array $signaturePlacementConfig,
    array $placements = [],
) {
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.design.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), [
            'placement_config' => [
                'schema_version' => 2,
                'placements' => $placements,
            ],
            'signature_placement_config' => $signaturePlacementConfig,
        ]);
}
function makePdfOverlayDraftWithPages(int $pageCount = 2): array
{
    $user = User::factory()->create();
    $company = createSignaturePlacementTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update', 'documents.templates.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_page_count' => $pageCount,
        'placement_config' => ['schema_version' => 1, 'placements' => []],
        'signature_placement_config' => null,
    ]);

    return compact('user', 'company', 'template', 'version');
}

test('authorized company user can save subject signature placement on draft pdf overlay version', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages(2);
    $payload = validSubjectSignaturePlacement(2);

    putUnifiedDesignerDraft($user, $company, $template, $version, $payload)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('version.has_signature_placement', true);

    $version->refresh();
    expect($version->signature_placement_config['schema_version'])->toBe(3)
        ->and($version->signature_placement_config['placements'])->toHaveCount(1)
        ->and($version->signature_placement_config['placements'][0]['x'])->toBe(0.12)
        ->and($version->signature_placement_config['placements'][0]['role'])->toBe('subject')
        ->and($version->signature_placement_config['placements'][0]['slot_key'])->toBe('subject')
        ->and($version->signature_placement_config['placements'][0]['text_align'])->toBe('center')
        ->and($version->signature_placement_config['placements'][0]['vertical_align'])->toBe('middle')
        ->and($version->toArraySummary()['has_signature_placement'])->toBeTrue();
});

test('unified designer persists signature alignment', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages(1);

    $payload = [
        'schema_version' => 3,
        'placements' => [[
            'id' => 'subject_signature',
            'type' => 'signature',
            'role' => 'subject',
            'slot_key' => 'subject',
            'page' => 1,
            'x' => 0.12,
            'y' => 0.72,
            'width' => 0.28,
            'height' => 0.09,
            'required' => true,
            'text_align' => 'left',
            'vertical_align' => 'top',
        ]],
    ];

    putUnifiedDesignerDraft($user, $company, $template, $version, $payload)
        ->assertOk();

    $version->refresh();
    expect($version->signature_placement_config['placements'][0]['text_align'])->toBe('left')
        ->and($version->signature_placement_config['placements'][0]['vertical_align'])->toBe('top');
});

test('unified designer can persist two subject placements as schema v3', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages(1);

    $payload = [
        'schema_version' => 3,
        'placements' => [
            [
                'id' => 'employee_signature_en',
                'type' => 'signature',
                'role' => 'subject',
                'slot_key' => 'subject',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.72,
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
                'y' => 0.72,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
        ],
    ];

    putUnifiedDesignerDraft($user, $company, $template, $version, $payload)
        ->assertOk();

    $version->refresh();
    expect($version->signature_placement_config['schema_version'])->toBe(3)
        ->and($version->signature_placement_config['placements'])->toHaveCount(2)
        ->and(collect($version->signature_placement_config['placements'])->pluck('id')->all())
        ->toBe(['employee_signature_en', 'employee_signature_ar'])
        ->and(collect($version->signature_placement_config['placements'])->pluck('slot_key')->unique()->all())
        ->toBe(['subject']);
});

test('published version retains configured signature placement', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages(1);
    $payload = validSubjectSignaturePlacement();

    $pdfPath = DocumentTemplateStorage::directory($company->id).'/source.pdf';
    Storage::disk('local')->put($pdfPath, minimalPdfBytes());
    $signingPreset = app(StoreDocumentSigningPreset::class)->handle(
        $user,
        $company->id,
        'Subject signing',
        null,
        [['recipient_role' => 'subject']],
    );
    $version->update([
        'source_pdf_path' => $pdfPath,
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::Preset,
        'document_signing_preset_id' => $signingPreset->id,
    ]);

    app(SaveDocumentGenerationTemplateSignaturePlacement::class)->handle($version, $payload, $user->id);

    seedAuthoritativeValidLayoutRun($template, $version->fresh());
    $published = app(PublishDocumentGenerationTemplateVersion::class)->handle($version->fresh(), $user->id);

    expect($published->isPublished())->toBeTrue()
        ->and($published->signature_placement_config['schema_version'])->toBe(3)
        ->and($published->signature_placement_config['placements'][0]['slot_key'])->toBe('subject')
        ->and($published->toArraySummary()['has_signature_placement'])->toBeTrue();
});

test('create draft copies published v3 placements and leaves the published version immutable', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages(1);
    $payload = [
        'schema_version' => 3,
        'placements' => [
            [
                'id' => 'employee_signature_en',
                'type' => 'signature',
                'role' => 'subject',
                'slot_key' => 'subject',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.72,
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
                'y' => 0.72,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
        ],
    ];

    $pdfPath = DocumentTemplateStorage::directory($company->id).'/source.pdf';
    Storage::disk('local')->put($pdfPath, minimalPdfBytes());
    $signingPreset = app(StoreDocumentSigningPreset::class)->handle(
        $user,
        $company->id,
        'Subject signing',
        null,
        [['recipient_role' => 'subject']],
    );
    $version->update([
        'source_pdf_path' => $pdfPath,
        'document_workflow_mode' => DocumentTemplateAutomationMode::None,
        'document_signing_mode' => DocumentTemplateAutomationMode::Preset,
        'document_signing_preset_id' => $signingPreset->id,
    ]);

    app(SaveDocumentGenerationTemplateSignaturePlacement::class)->handle($version, $payload, $user->id);
    seedAuthoritativeValidLayoutRun($template, $version->fresh());
    $published = app(PublishDocumentGenerationTemplateVersion::class)->handle($version->fresh(), $user->id);
    $publishedChecksum = $published->signature_placement_config;

    $draft = app(BranchDocumentGenerationTemplateDraft::class)->handle($template->fresh(), $user->id);

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->id)->not->toBe($published->id)
        ->and($draft->signature_placement_config['schema_version'])->toBe(3)
        ->and($draft->signature_placement_config['placements'])->toHaveCount(2)
        ->and($published->fresh()->signature_placement_config)->toBe($publishedChecksum);
});

test('unsupported signature placement schema is rejected', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $payload = validSubjectSignaturePlacement();
    $payload['schema_version'] = 99;

    putUnifiedDesignerDraft($user, $company, $template, $version, $payload)
        ->assertUnprocessable();
});

test('invalid page is rejected', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages(1);

    putUnifiedDesignerDraft($user, $company, $template, $version, validSubjectSignaturePlacement(3))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['signature_placement_config']);
});

test('out of bounds coordinates are rejected', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $payload = validSubjectSignaturePlacement();
    $payload['placements'][0]['x'] = 0.9;
    $payload['placements'][0]['width'] = 0.2;

    putUnifiedDesignerDraft($user, $company, $template, $version, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['signature_placement_config']);
});

test('unsupported role and type are rejected', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $directorPayload = validSubjectSignaturePlacement();
    $directorPayload['placements'][0]['role'] = 'director';

    putUnifiedDesignerDraft($user, $company, $template, $version, $directorPayload)
        ->assertUnprocessable();

    $initialPayload = validSubjectSignaturePlacement();
    $initialPayload['placements'][0]['type'] = 'initial';

    putUnifiedDesignerDraft($user, $company, $template, $version, $initialPayload)
        ->assertUnprocessable();
});

test('multiple subject placements are rejected', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $payload = validSubjectSignaturePlacement();
    $payload['placements'][] = [
        'id' => 'subject_signature_2',
        'type' => 'signature',
        'role' => 'subject',
        'page' => 1,
        'x' => 0.1,
        'y' => 0.5,
        'width' => 0.2,
        'height' => 0.08,
        'required' => true,
    ];

    putUnifiedDesignerDraft($user, $company, $template, $version, $payload)
        ->assertUnprocessable();
});

test('published and archived versions cannot be edited', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $version->status = DocumentGenerationTemplateVersionStatus::Published;
    $version->published_at = now();
    $version->saveQuietly();

    putUnifiedDesignerDraft($user, $company, $template, $version, validSubjectSignaturePlacement())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['version']);

    $version->status = DocumentGenerationTemplateVersionStatus::Archived;
    $version->saveQuietly();

    putUnifiedDesignerDraft($user, $company, $template, $version, validSubjectSignaturePlacement())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['version']);
});

test('user without templates update permission is forbidden', function () {
    ['company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();
    $viewer = User::factory()->create();
    grantCompanyPermissions($viewer, $company, ['documents.templates.view']);

    putUnifiedDesignerDraft($viewer, $company, $template, $version, validSubjectSignaturePlacement())
        ->assertForbidden();
});

test('company a cannot edit company b template version', function () {
    ['user' => $userA, 'company' => $companyA] = makePdfOverlayDraftWithPages();
    ['template' => $templateB, 'version' => $versionB] = makePdfOverlayDraftWithPages();

    putUnifiedDesignerDraft($userA, $companyA, $templateB, $versionB, validSubjectSignaturePlacement())
        ->assertNotFound();
});

test('version cannot be submitted under another template', function () {
    ['user' => $user, 'company' => $company, 'template' => $templateA, 'version' => $versionA] = makePdfOverlayDraftWithPages();

    $templateB = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    DocumentGenerationTemplateVersion::factory()->forTemplate($templateB)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_page_count' => 1,
    ]);

    putUnifiedDesignerDraft($user, $company, $templateB, $versionA, validSubjectSignaturePlacement())
        ->assertNotFound();
});

test('atomic save persists merge field placements together with signature placement', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $mergePlacements = [[
        'id' => 'name-1',
        'type' => 'field',
        'field' => '{{employee_name}}',
        'page' => 1,
        'x' => 0.1,
        'y' => 0.1,
        'width' => 0.3,
        'height' => 0.04,
        'font_size' => 12,
        'font_weight' => 'normal',
        'text_align' => 'left',
    ]];

    putUnifiedDesignerDraft($user, $company, $template, $version, validSubjectSignaturePlacement(), $mergePlacements)
        ->assertOk();

    $version->refresh();
    expect($version->placement_config['schema_version'])->toBe(2)
        ->and($version->placement_config['placements'][0]['id'])->toBe('name-1')
        ->and($version->signature_placement_config['placements'])->toHaveCount(1);
});

test('published configured pdf overlay version resolves signature placement and unblocks sign eligibility', function () {
    $company = createSignaturePlacementTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $templateVersion = DocumentGenerationTemplateVersion::factory()
        ->forTemplate($template)
        ->published()
        ->create([
            'source_pdf_page_count' => 1,
            'signature_placement_config' => validSubjectSignaturePlacement(),
        ]);
    $template->update(['published_version_id' => $templateVersion->id]);

    $pdfBytes = minimalPdfBytes();
    $libraryPath = "employee-documents/{$company->id}/{$employee->id}/letter.pdf";
    $canonicalPath = "document-instances/{$company->id}/canonical.pdf";
    Storage::disk('local')->put($libraryPath, $pdfBytes);
    Storage::disk('local')->put($canonicalPath, $pdfBytes);

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Generated Letter',
        'file_path' => $libraryPath,
        'original_filename' => 'letter.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($pdfBytes),
        'checksum' => hash('sha256', $pdfBytes),
        'current_version' => 1,
        'status' => 'valid',
    ]);

    $instance = DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => $employee->name,
        'employee_no_snapshot' => $employee->employee_no,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => $templateVersion->version,
        'title_snapshot' => 'Generated Letter',
        'status' => 'generated',
        'employee_document_id' => $document->id,
        'generated_at' => now(),
    ]);

    $version = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 1,
        'file_path' => $canonicalPath,
        'original_filename' => 'canonical.pdf',
        'size_bytes' => strlen($pdfBytes),
        'checksum' => hash('sha256', $pdfBytes),
    ]);
    $instance->update(['current_version_id' => $version->id]);

    $resolved = app(ResolveDocumentSignaturePlacement::class)->forInstanceVersion($instance->fresh(), $version);
    expect($resolved['id'])->toBe('subject_signature')
        ->and($resolved['role'])->toBe('subject')
        ->and($resolved['page'])->toBe(1);

    $eligibility = app(DocumentRecipientRequestEligibility::class)->forDocument($document->fresh(), $company->id);
    expect($eligibility['can_request_sign'])->toBeTrue()
        ->and($eligibility['sign_blocked_reason'])->toBeNull();
});

test('pdf overlay version without signature placement remains blocked for sign requests', function () {
    $company = createSignaturePlacementTestCompany();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $templateVersion = DocumentGenerationTemplateVersion::factory()
        ->forTemplate($template)
        ->published()
        ->create([
            'source_pdf_page_count' => 1,
            'signature_placement_config' => null,
        ]);
    $template->update(['published_version_id' => $templateVersion->id]);

    $pdfBytes = minimalPdfBytes();
    $libraryPath = "employee-documents/{$company->id}/{$employee->id}/letter.pdf";
    $canonicalPath = "document-instances/{$company->id}/canonical.pdf";
    Storage::disk('local')->put($libraryPath, $pdfBytes);
    Storage::disk('local')->put($canonicalPath, $pdfBytes);

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'other',
        'title' => 'Generated Letter',
        'file_path' => $libraryPath,
        'original_filename' => 'letter.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($pdfBytes),
        'checksum' => hash('sha256', $pdfBytes),
        'current_version' => 1,
        'status' => 'valid',
    ]);

    $instance = DocumentInstance::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_name_snapshot' => $employee->name,
        'employee_no_snapshot' => $employee->employee_no,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $templateVersion->id,
        'template_name_snapshot' => $template->name,
        'template_version_number' => $templateVersion->version,
        'title_snapshot' => 'Generated Letter',
        'status' => 'generated',
        'employee_document_id' => $document->id,
        'generated_at' => now(),
    ]);

    $version = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 1,
        'file_path' => $canonicalPath,
        'original_filename' => 'canonical.pdf',
        'size_bytes' => strlen($pdfBytes),
        'checksum' => hash('sha256', $pdfBytes),
    ]);
    $instance->update(['current_version_id' => $version->id]);

    expect(fn () => app(ResolveDocumentSignaturePlacement::class)->forInstanceVersion($instance->fresh(), $version))
        ->toThrow(ValidationException::class);

    $eligibility = app(DocumentRecipientRequestEligibility::class)->forDocument($document->fresh(), $company->id);
    expect($eligibility['can_request_sign'])->toBeFalse()
        ->and($eligibility['sign_blocked_reason'])->toContain('trusted signature placement');
});
