<?php

use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
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
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\SaveDocumentGenerationTemplateSignaturePlacement;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEligibility;
use App\Support\Documents\RecipientRequests\ResolveDocumentSignaturePlacement;
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

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), $payload)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('signature_placement_config.schema_version', 1)
        ->assertJsonPath('signature_placement_config.placements.0.id', 'subject_signature')
        ->assertJsonPath('signature_placement_config.placements.0.page', 2);

    $version->refresh();
    expect($version->signature_placement_config['schema_version'])->toBe(1)
        ->and($version->signature_placement_config['placements'])->toHaveCount(1)
        ->and($version->signature_placement_config['placements'][0]['x'])->toBe(0.12)
        ->and($version->signature_placement_config['placements'][0]['role'])->toBe('subject')
        ->and($version->toArraySummary()['has_signature_placement'])->toBeTrue();
});

test('published version retains configured signature placement', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages(1);
    $payload = validSubjectSignaturePlacement();

    app(SaveDocumentGenerationTemplateSignaturePlacement::class)->handle($version, $payload, $user->id);

    $published = app(PublishDocumentGenerationTemplateVersion::class)->handle($version->fresh(), $user->id);

    expect($published->isPublished())->toBeTrue()
        ->and($published->signature_placement_config)->toBe($payload)
        ->and($published->toArraySummary()['has_signature_placement'])->toBeTrue();
});

test('invalid signature placement schema is rejected', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $payload = validSubjectSignaturePlacement();
    $payload['schema_version'] = 2;

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), $payload)
        ->assertUnprocessable();
});

test('invalid page is rejected', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages(1);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), validSubjectSignaturePlacement(3))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['signature_placement_config']);
});

test('out of bounds coordinates are rejected', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $payload = validSubjectSignaturePlacement();
    $payload['placements'][0]['x'] = 0.9;
    $payload['placements'][0]['width'] = 0.2;

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['signature_placement_config']);
});

test('unsupported role and type are rejected', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $managerPayload = validSubjectSignaturePlacement();
    $managerPayload['placements'][0]['role'] = 'manager';

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), $managerPayload)
        ->assertUnprocessable();

    $initialPayload = validSubjectSignaturePlacement();
    $initialPayload['placements'][0]['type'] = 'initial';

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), $initialPayload)
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

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), $payload)
        ->assertUnprocessable();
});

test('published and archived versions cannot be edited', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $version->status = DocumentGenerationTemplateVersionStatus::Published;
    $version->published_at = now();
    $version->saveQuietly();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), validSubjectSignaturePlacement())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['version']);

    $version->status = DocumentGenerationTemplateVersionStatus::Archived;
    $version->saveQuietly();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), validSubjectSignaturePlacement())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['version']);
});

test('user without templates update permission is forbidden', function () {
    ['company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();
    $viewer = User::factory()->create();
    grantCompanyPermissions($viewer, $company, ['documents.templates.view']);

    $this->actingAs($viewer)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), validSubjectSignaturePlacement())
        ->assertForbidden();
});

test('company a cannot edit company b template version', function () {
    ['user' => $userA, 'company' => $companyA] = makePdfOverlayDraftWithPages();
    ['template' => $templateB, 'version' => $versionB] = makePdfOverlayDraftWithPages();

    $this->actingAs($userA)
        ->withSession(['current_company_id' => $companyA->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $templateB->id,
            'version' => $versionB->id,
        ]), validSubjectSignaturePlacement())
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

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $templateB->id,
            'version' => $versionA->id,
        ]), validSubjectSignaturePlacement())
        ->assertNotFound();
});

test('merge field placements remain unchanged when saving signature placement', function () {
    ['user' => $user, 'company' => $company, 'template' => $template, 'version' => $version] = makePdfOverlayDraftWithPages();

    $mergeConfig = [
        'schema_version' => 1,
        'placements' => [[
            'id' => 'name-1',
            'field' => '{{employee_name}}',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.1,
            'width' => 0.3,
            'height' => 0.04,
            'font_size' => 12,
            'font_weight' => 'normal',
            'text_align' => 'left',
        ]],
    ];
    $version->placement_config = $mergeConfig;
    $version->save();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.signature-placement.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), validSubjectSignaturePlacement())
        ->assertOk();

    $version->refresh();
    expect($version->placement_config)->toBe($mergeConfig)
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
