<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
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
use setasign\Fpdi\Fpdi;

function createPdfTestCompany(string $name = 'PDF Test Co'): Company
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

function createSamplePdfContent(int $pages = 2): string
{
    $fpdi = new Fpdi;
    for ($i = 1; $i <= $pages; $i++) {
        $fpdi->AddPage();
        $fpdi->SetFont('Helvetica', '', 12);
        $fpdi->Write(10, "Page {$i} Content for Automated Testing");
    }

    return $fpdi->Output('S');
}

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake(DocumentTemplateStorage::DISK);
});

test('store creates PDF template and v1 draft version with inspected metadata', function () {
    $user = User::factory()->create();
    $company = createPdfTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    $pdfContent = createSamplePdfContent(3);
    $uploadedFile = UploadedFile::fake()->createWithContent('contract.pdf', $pdfContent);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
            'name' => 'Standard Employment Contract',
            'description' => 'Contract template on company letterhead',
            'file' => $uploadedFile,
        ]);

    $response->assertRedirect();

    $template = DocumentGenerationTemplate::query()
        ->where('company_id', $company->id)
        ->where('name', 'Standard Employment Contract')
        ->first();

    expect($template)->not->toBeNull();
    expect($template->isPdfOverlay())->toBeTrue();
    expect($template->status)->toBe(DocumentGenerationTemplateStatus::Draft);

    $draft = $template->draftVersion;
    expect($draft)->not->toBeNull();
    expect($draft->version)->toBe(1);
    expect($draft->source_pdf_original_name)->toBe('contract.pdf');
    expect($draft->source_pdf_page_count)->toBe(3);
    expect($draft->source_pdf_size_bytes)->toBeGreaterThan(0);
    expect(Storage::disk(DocumentTemplateStorage::DISK)->exists($draft->source_pdf_path))->toBeTrue();
});

test('store rejects non-pdf or corrupted files', function () {
    $user = User::factory()->create();
    $company = createPdfTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    // Fake text file named .pdf
    $fakePdf = UploadedFile::fake()->createWithContent('fake.pdf', 'Not a valid pdf header');

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
            'name' => 'Corrupt PDF Template',
            'file' => $fakePdf,
        ]);

    $response->assertSessionHasErrors('file');
});

test('source pdf endpoint streams private file with tenant isolation', function () {
    $user = User::factory()->create();
    $companyA = createPdfTestCompany('Company A');
    $companyB = createPdfTestCompany('Company B');

    grantCompanyPermissions($user, $companyA, ['documents.templates.view']);
    grantCompanyPermissions($user, $companyB, ['documents.templates.view']);

    $pdfContent = createSamplePdfContent(1);
    $path = Storage::disk(DocumentTemplateStorage::DISK)->put(
        DocumentTemplateStorage::directory($companyA->id).'/test.pdf',
        $pdfContent
    );

    $template = DocumentGenerationTemplate::factory()->forCompany($companyA)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'source_pdf_path' => DocumentTemplateStorage::directory($companyA->id).'/test.pdf',
        'source_pdf_original_name' => 'test.pdf',
        'source_pdf_page_count' => 1,
    ]);

    // Authorized user in Company A can stream the PDF
    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get(route('organization.documents.templates.versions.source-pdf', [
            'template' => $template->id,
            'version' => $version->id,
        ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');

    // Tenant isolation: Same user switched to Company B cannot access Company A's PDF (404)
    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyB->id])
        ->get(route('organization.documents.templates.versions.source-pdf', [
            'template' => $template->id,
            'version' => $version->id,
        ]))
        ->assertNotFound();
});

test('saving placements validates normalized coordinates and schema version', function () {
    $user = User::factory()->create();
    $company = createPdfTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_page_count' => 2,
    ]);

    $validPlacements = [
        [
            'id' => 'placement-1',
            'field' => '{{employee_name}}',
            'page' => 1,
            'x' => 0.15,
            'y' => 0.25,
            'width' => 0.35,
            'height' => 0.04,
            'font_size' => 12,
            'font_weight' => 'bold',
            'text_align' => 'left',
        ],
        [
            'id' => 'placement-2',
            'field' => '{{today}}',
            'page' => 2,
            'x' => 0.70,
            'y' => 0.85,
            'width' => 0.20,
            'height' => 0.04,
            'font_size' => 10,
            'font_weight' => 'normal',
            'text_align' => 'right',
        ],
    ];

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.placements.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), ['placements' => $validPlacements]);

    $response->assertOk();

    $version->refresh();
    expect($version->placement_config['schema_version'])->toBe(1);
    expect(count($version->placement_config['placements']))->toBe(2);

    // Reject out of bounds coordinate
    $invalidPlacements = [
        [
            'id' => 'placement-invalid',
            'field' => '{{employee_name}}',
            'page' => 1,
            'x' => 0.90,
            'y' => 0.50,
            'width' => 0.25, // x + width = 1.15 > 1.0!
            'height' => 0.04,
        ],
    ];

    $invalidResponse = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->putJson(route('organization.documents.templates.versions.placements.save', [
            'template' => $template->id,
            'version' => $version->id,
        ]), ['placements' => $invalidPlacements]);

    $invalidResponse->assertUnprocessable();
});

test('replacing pdf updates file, clears placements, and removes old file', function () {
    $user = User::factory()->create();
    $company = createPdfTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $oldContent = createSamplePdfContent(1);
    $oldPath = DocumentTemplateStorage::directory($company->id).'/old.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($oldPath, $oldContent);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => $oldPath,
        'source_pdf_page_count' => 1,
        'placement_config' => [
            'schema_version' => 1,
            'placements' => [['field' => '{{employee_name}}', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.05]],
        ],
    ]);

    $newContent = createSamplePdfContent(4);
    $newFile = UploadedFile::fake()->createWithContent('new-version.pdf', $newContent);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.versions.replace-pdf', [
            'template' => $template->id,
            'version' => $version->id,
        ]), ['file' => $newFile]);

    $response->assertRedirect();

    $version->refresh();
    expect($version->source_pdf_page_count)->toBe(4);
    expect($version->placement_config)->toBeNull();
    expect(Storage::disk(DocumentTemplateStorage::DISK)->exists($oldPath))->toBeFalse();
    expect(Storage::disk(DocumentTemplateStorage::DISK)->exists($version->source_pdf_path))->toBeTrue();
});

test('duplicating pdf template physically clones source pdf to new private file', function () {
    $user = User::factory()->create();
    $company = createPdfTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $pdfContent = createSamplePdfContent(2);
    $path = DocumentTemplateStorage::directory($company->id).'/original.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path, $pdfContent);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'name' => 'Original PDF Template',
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    $v1 = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $path,
        'source_pdf_page_count' => 2,
        'placement_config' => [
            'schema_version' => 1,
            'placements' => [['field' => '{{employee_name}}', 'page' => 1, 'x' => 0.2, 'y' => 0.2, 'width' => 0.3, 'height' => 0.05]],
        ],
    ]);
    $template->published_version_id = $v1->id;
    $template->save();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.duplicate', ['template' => $template->id]))
        ->assertRedirect();

    $copy = DocumentGenerationTemplate::query()
        ->where('company_id', $company->id)
        ->where('name', 'Original PDF Template (Copy)')
        ->first();

    expect($copy)->not->toBeNull();
    expect($copy->isPdfOverlay())->toBeTrue();

    $copyDraft = $copy->draftVersion;
    expect($copyDraft)->not->toBeNull();
    expect($copyDraft->version)->toBe(1);
    expect($copyDraft->source_pdf_path)->not->toBe($path);
    expect(Storage::disk(DocumentTemplateStorage::DISK)->exists($copyDraft->source_pdf_path))->toBeTrue();
    expect($copyDraft->placement_config['placements'])->toHaveCount(1);
});

test('deleting pdf template cleans up all source pdf files from disk', function () {
    $user = User::factory()->create();
    $company = createPdfTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.delete']);

    $pdfContent = createSamplePdfContent(1);
    $path1 = DocumentTemplateStorage::directory($company->id).'/v1.pdf';
    $path2 = DocumentTemplateStorage::directory($company->id).'/v2.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($path1, $pdfContent);
    Storage::disk(DocumentTemplateStorage::DISK)->put($path2, $pdfContent);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
        'source_pdf_path' => $path1,
    ]);

    DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 2,
        'source_pdf_path' => $path2,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.documents.templates.destroy', ['template' => $template->id]))
        ->assertRedirect();

    expect(Storage::disk(DocumentTemplateStorage::DISK)->exists($path1))->toBeFalse();
    expect(Storage::disk(DocumentTemplateStorage::DISK)->exists($path2))->toBeFalse();
    expect(DocumentGenerationTemplate::query()->find($template->id))->toBeNull();
});
