<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\User;
use App\Support\Documents\DocumentTemplateStorage;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use setasign\Fpdi\Fpdi;
use Spatie\Activitylog\Models\Activity;

function createTemplatePagesTestCompany(string $name = 'Pages Test Co'): Company
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

function createTemplatePagesSamplePdf(int $pages = 1): string
{
    $fpdi = new Fpdi;
    for ($i = 1; $i <= $pages; $i++) {
        $fpdi->AddPage();
        $fpdi->SetFont('Helvetica', '', 12);
        $fpdi->Write(10, "Page {$i}");
    }

    return $fpdi->Output('S');
}

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('create route redirects to pdf create page for authorized users', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.create'))
        ->assertRedirect(route('organization.documents.templates.create.pdf'));

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.create.pdf'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/templates/create-pdf')
            ->has('document_types'));
});

test('unauthorized users cannot open template create pages', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.create'))
        ->assertForbidden();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.create.pdf'))
        ->assertForbidden();
});

test('edit route redirects to templates index for authorized users', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->content()->create([
        'name' => 'Legacy Content Template',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.edit', $template))
        ->assertRedirect(route('organization.documents.templates'));
});

test('edit route rejects another company templates', function () {
    $user = User::factory()->create();
    $companyA = createTemplatePagesTestCompany('Alpha Co');
    $companyB = createTemplatePagesTestCompany('Beta Co');
    grantCompanyPermissions($user, $companyA, ['documents.templates.update']);
    grantCompanyPermissions($user, $companyB, ['documents.templates.update']);

    $template = DocumentGenerationTemplate::factory()->forCompany($companyB)->content()->create();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get(route('organization.documents.templates.edit', $template))
        ->assertNotFound();
});

test('authorized users can open the design page which no longer auto-creates a draft', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);
    Storage::fake(DocumentTemplateStorage::DISK);

    $pdfContent = createTemplatePagesSamplePdf();
    $path = DocumentTemplateStorage::directory($company->id).'/letter.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, $pdfContent);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create([
        'name' => 'Designable PDF',
    ]);

    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $path,
        'source_pdf_original_name' => 'letter.pdf',
        'source_pdf_page_count' => 1,
        'source_pdf_size_bytes' => strlen($pdfContent),
    ]);
    $template->published_version_id = $published->id;
    $template->save();

    $versionCountBefore = DocumentGenerationTemplateVersion::query()
        ->where('document_generation_template_id', $template->id)
        ->count();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.design', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/templates/design')
            ->where('template.id', $template->id)
            ->where('template.name', 'Designable PDF')
            ->has('initial_version')
            ->has('all_versions')
            ->has('workflow_presets')
            ->has('readiness')
            ->has('can'));

    expect(DocumentGenerationTemplateVersion::query()
        ->where('document_generation_template_id', $template->id)
        ->count()
    )->toBe($versionCountBefore);
});

test('design page forbids unauthorized users', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.design', $template))
        ->assertForbidden();
});

test('design page returns 404 for another company template', function () {
    $user = User::factory()->create();
    $companyA = createTemplatePagesTestCompany('Company A');
    $companyB = createTemplatePagesTestCompany('Company B');
    grantCompanyPermissions($user, $companyA, ['documents.templates.update']);
    grantCompanyPermissions($user, $companyB, ['documents.templates.update']);

    $template = DocumentGenerationTemplate::factory()->forCompany($companyB)->pdfOverlay()->create();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get(route('organization.documents.templates.design', $template))
        ->assertNotFound();
});

test('content store is prohibited and requires pdf format and file', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'name' => 'Redirect Content Template',
            'content' => 'Hello {{employee_name}}',
        ])
        ->assertSessionHasErrors(['template_format', 'file', 'content']);
});

test('pdf store redirects to the design page', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.create']);
    Storage::fake(DocumentTemplateStorage::DISK);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
            'name' => 'Redirect PDF Template',
            'file' => UploadedFile::fake()->createWithContent(
                'contract.pdf',
                createTemplatePagesSamplePdf(2),
            ),
        ]);

    $template = DocumentGenerationTemplate::query()
        ->where('company_id', $company->id)
        ->where('name', 'Redirect PDF Template')
        ->firstOrFail();

    $response->assertRedirect(route('organization.documents.templates.design', $template));
});

// New design page tests (version switcher)

test('design page renders with initial_version prop and does NOT auto-create a draft', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    Storage::fake(DocumentTemplateStorage::DISK);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    $publishedVersion = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Published,
        'source_pdf_page_count' => 1,
        'published_at' => now(),
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);

    $versionCountBefore = DocumentGenerationTemplateVersion::query()
        ->where('document_generation_template_id', $template->id)
        ->count();

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.design', $template));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('organization/documents/templates/design')
        ->has('template')
        ->has('initial_version')
        ->has('all_versions')
        ->has('workflow_presets')
        ->has('signing_presets')
        ->has('readiness')
        ->has('can')
        ->where('initial_version.status', 'published')
        ->where('initial_change_summary', null)
        ->where('can.create_draft', true)
        ->where('can.update', true)
        ->where('can.preview_employee', false)
    );

    $versionCountAfter = DocumentGenerationTemplateVersion::query()
        ->where('document_generation_template_id', $template->id)
        ->count();
    expect($versionCountAfter)->toBe($versionCountBefore);
});

test('design page selects draft as initial_version when draft exists', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    Storage::fake(DocumentTemplateStorage::DISK);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1, 'status' => DocumentGenerationTemplateVersionStatus::Published,
        'source_pdf_page_count' => 1, 'published_at' => now(),
    ]);

    $draftVersion = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 2, 'status' => DocumentGenerationTemplateVersionStatus::Draft, 'source_pdf_page_count' => 1,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.design', $template));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('initial_version.id', $draftVersion->id)
        ->where('initial_version.status', 'draft')
        ->where('initial_change_summary.compared_to_version', 1)
    );
});

test('design page includes the initial change summary for v2 against the previous version', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    Storage::fake(DocumentTemplateStorage::DISK);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Archived,
        'source_pdf_page_count' => 1,
        'published_at' => now()->subDay(),
        'source_pdf_original_name' => 'old.pdf',
        'placement_config' => [
            'schema_version' => 1,
            'placements' => [[
                'id' => 'p1', 'field' => '{{employee_name}}',
                'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05,
                'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left',
            ]],
        ],
    ]);

    $draftVersion = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 2,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_page_count' => 1,
        'source_pdf_original_name' => 'new.pdf',
        'placement_config' => [
            'schema_version' => 2,
            'placements' => [
                [
                    'id' => 'p1', 'type' => 'field', 'field' => '{{employee_name}}',
                    'page' => 1, 'x' => 0.2, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05,
                    'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left',
                ],
                [
                    'id' => 'p2', 'type' => 'field', 'field' => '{{today}}',
                    'page' => 1, 'x' => 0.5, 'y' => 0.1, 'width' => 0.2, 'height' => 0.05,
                    'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left',
                ],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.design', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('initial_version.id', $draftVersion->id)
            ->where('initial_change_summary.compared_to_version', 1)
            ->where('initial_change_summary.fields_added', 1)
            ->where('initial_change_summary.fields_moved', 1)
            ->where('initial_change_summary.pdf_metadata_changed', true)
        );
});

test('design page returns 404 when template has no versions', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.design', $template))
        ->assertNotFound();
});

test('all_versions does not include placement_config or signature_placement_config arrays', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    Storage::fake(DocumentTemplateStorage::DISK);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1, 'status' => DocumentGenerationTemplateVersionStatus::Published,
        'source_pdf_page_count' => 1, 'published_at' => now(),
        'placement_config' => ['schema_version' => 1, 'placements' => [['id' => 'p1', 'field' => '{{employee_name}}', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05, 'font_size' => 12, 'font_weight' => 'normal', 'text_align' => 'left']]],
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates.design', $template));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('all_versions', 1)
        ->missing('all_versions.0.placement_config')
        ->missing('all_versions.0.signature_placement_config')
    );
});

// showVersion tests

test('showVersion returns version summary and change_summary', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.view']);

    Storage::fake(DocumentTemplateStorage::DISK);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1, 'status' => DocumentGenerationTemplateVersionStatus::Published,
        'source_pdf_page_count' => 1, 'published_at' => now(),
        'placement_config' => ['schema_version' => 1, 'placements' => []],
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.versions.show', [
            'template' => $template->id, 'version' => $version->id,
        ]));

    $response->assertOk();
    $response->assertJsonStructure(['version', 'change_summary']);
    expect($response->json('change_summary'))->toBeNull();
    expect($response->json('version'))->not->toHaveKey('source_pdf_path');
});

test('showVersion does not create activity log entries', function () {
    $user = User::factory()->create();
    $company = createTemplatePagesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.view']);

    Storage::fake(DocumentTemplateStorage::DISK);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1, 'status' => DocumentGenerationTemplateVersionStatus::Published,
        'source_pdf_page_count' => 1, 'published_at' => now(),
    ]);

    $activityCountBefore = Activity::query()->count();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.versions.show', [
            'template' => $template->id, 'version' => $version->id,
        ]))
        ->assertOk();

    expect(Activity::query()->count())->toBe($activityCountBefore);
});

test('showVersion returns 404 for cross-company access', function () {
    $user = User::factory()->create();
    $companyA = createTemplatePagesTestCompany('Company A');
    $companyB = createTemplatePagesTestCompany('Company B');
    grantCompanyPermissions($user, $companyA, ['documents.templates.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($companyB)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1, 'status' => DocumentGenerationTemplateVersionStatus::Published,
        'source_pdf_page_count' => 1, 'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->getJson(route('organization.documents.templates.versions.show', [
            'template' => $template->id, 'version' => $version->id,
        ]))
        ->assertNotFound();
});
