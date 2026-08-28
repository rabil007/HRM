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
use App\Support\Documents\Actions\BranchDocumentGenerationTemplateDraft;
use App\Support\Documents\Actions\DuplicateDocumentGenerationTemplate;
use App\Support\Documents\Actions\PublishDocumentGenerationTemplateVersion;
use App\Support\Documents\Actions\ReplaceDocumentGenerationTemplatePdf;
use App\Support\Documents\Actions\SaveDocumentGenerationTemplatePlacements;
use App\Support\Documents\DocumentTemplateStorage;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Spatie\Activitylog\Models\Activity;

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
    expect($draft->placement_config)->toMatchArray([
        'schema_version' => 1,
        'placements' => [],
    ]);
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
    expect($version->placement_config)->toMatchArray([
        'schema_version' => 1,
        'placements' => [],
    ]);
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

test('save placements rejects when version is concurrently published', function () {
    $company = createPdfTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_page_count' => 1,
    ]);

    // Simulate concurrent publish
    $version->status = DocumentGenerationTemplateVersionStatus::Published;
    $version->saveQuietly();

    $action = new SaveDocumentGenerationTemplatePlacements;

    expect(fn () => $action->handle($version->fresh(), [
        [
            'field' => '{{employee_name}}',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.1,
            'width' => 0.2,
            'height' => 0.05,
        ],
    ]))->toThrow(DomainException::class, 'Published or archived template versions cannot be edited.');
});

test('replace pdf rejects when version is concurrently published and cleans up file', function () {
    $company = createPdfTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    $oldPdfContent = createSamplePdfContent(1);
    $oldPath = DocumentTemplateStorage::directory($company->id).'/original.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($oldPath, $oldPdfContent);

    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => $oldPath,
        'source_pdf_page_count' => 1,
    ]);

    // Simulate concurrent publish
    $version->status = DocumentGenerationTemplateVersionStatus::Published;
    $version->saveQuietly();

    $newFile = UploadedFile::fake()->createWithContent('new.pdf', createSamplePdfContent(2));
    $action = new ReplaceDocumentGenerationTemplatePdf;

    expect(fn () => $action->handle($version->fresh(), $newFile))
        ->toThrow(DomainException::class, 'Published or archived template versions cannot be edited.');

    // Original file remains
    expect(Storage::disk(DocumentTemplateStorage::DISK)->exists($oldPath))->toBeTrue();
    // Newly uploaded file was cleaned up (only original exists in directory)
    $files = Storage::disk(DocumentTemplateStorage::DISK)->files(DocumentTemplateStorage::directory($company->id));
    expect($files)->toHaveCount(1);
    expect($files[0])->toBe($oldPath);
});

test('copyPdf rejects source path outside company directory boundary', function () {
    $companyA = createPdfTestCompany('Company A');
    $companyB = createPdfTestCompany('Company B');

    $fileContent = createSamplePdfContent(1);
    $pathA = DocumentTemplateStorage::directory($companyA->id).'/company_a.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($pathA, $fileContent);

    // Attempting to copy Company A's PDF into Company B must fail
    expect(fn () => DocumentTemplateStorage::copyPdf($pathA, $companyB->id))
        ->toThrow(RuntimeException::class, 'outside the company storage boundary');

    // Attempting to copy an arbitrary relative or malicious path must fail
    expect(fn () => DocumentTemplateStorage::copyPdf('../secret.pdf', $companyA->id))
        ->toThrow(RuntimeException::class, 'invalid');
});

test('manual activity events record company_id and avoid logging sensitive contents', function () {
    $user = User::factory()->create();
    $company = createPdfTestCompany();

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'name' => 'Audit Test Template',
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    $pdfPath = DocumentTemplateStorage::directory($company->id).'/test.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($pdfPath, createSamplePdfContent(1));

    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => $pdfPath,
        'source_pdf_page_count' => 1,
    ]);

    // 1. Save Placements
    $saver = new SaveDocumentGenerationTemplatePlacements;
    $saver->handle($version, [
        [
            'field' => '{{employee_name}}',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.1,
            'width' => 0.2,
            'height' => 0.05,
            'text_align' => 'center',
        ],
    ], $user->id);

    $placementActivity = Activity::forSubject($template)->latest('id')->first();
    expect($placementActivity)->not->toBeNull();
    expect($placementActivity->company_id)->toBe($company->id);
    expect($placementActivity->properties->toArray())->not->toHaveKey('placement_config');

    // 2. Publish
    $publisher = new PublishDocumentGenerationTemplateVersion;
    $publisher->handle($version, $user->id);

    $publishActivity = Activity::forSubject($template)->latest('id')->first();
    expect($publishActivity)->not->toBeNull();
    expect($publishActivity->company_id)->toBe($company->id);

    // 3. Branch Draft
    $brancher = new BranchDocumentGenerationTemplateDraft;
    $brancher->handle($template, $user->id);

    $branchActivity = Activity::forSubject($template)->latest('id')->first();
    expect($branchActivity)->not->toBeNull();
    expect($branchActivity->company_id)->toBe($company->id);

    // 4. Duplicate
    $duplicator = new DuplicateDocumentGenerationTemplate;
    $copy = $duplicator->handle($template, $user);

    $duplicateActivity = Activity::forSubject($copy)->latest('id')->first();
    expect($duplicateActivity)->not->toBeNull();
    expect($duplicateActivity->company_id)->toBe($company->id);
    expect($duplicateActivity->properties->toArray())->not->toHaveKey('content');
});

test('save placements validates and stores text alignment options', function () {
    $user = User::factory()->create();
    $company = createPdfTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $pdfPath = DocumentTemplateStorage::directory($company->id).'/test.pdf';
    Storage::disk(DocumentTemplateStorage::DISK)->put($pdfPath, createSamplePdfContent(1));

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
    ]);

    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => $pdfPath,
        'source_pdf_page_count' => 1,
    ]);

    $saver = new SaveDocumentGenerationTemplatePlacements;
    $updated = $saver->handle($version, [
        [
            'id' => 'p-left',
            'field' => '{{employee_name}}',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.1,
            'width' => 0.2,
            'height' => 0.05,
            'text_align' => 'left',
        ],
        [
            'id' => 'p-center',
            'field' => '{{company_name}}',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.2,
            'width' => 0.2,
            'height' => 0.05,
            'text_align' => 'center',
        ],
        [
            'id' => 'p-right',
            'field' => '{{today}}',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.3,
            'width' => 0.2,
            'height' => 0.05,
            'text_align' => 'right',
        ],
    ], $user->id);

    $placements = $updated->placement_config['placements'];
    expect($placements[0]['text_align'])->toBe('left');
    expect($placements[1]['text_align'])->toBe('center');
    expect($placements[2]['text_align'])->toBe('right');
});

it('includes placement_config in version toArraySummary', function () {
    $company = createPdfTestCompany('Placement Summary Co');
    $template = DocumentGenerationTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Summary Test',
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'status' => DocumentGenerationTemplateStatus::Draft,
        'content' => '',
    ]);

    $config = [
        'schema_version' => 1,
        'placements' => [
            ['id' => 'p1', 'field' => '{{employee_name}}', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.05],
        ],
    ];

    $version = DocumentGenerationTemplateVersion::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'placement_config' => $config,
    ]);

    $summary = $version->toArraySummary();
    expect($summary)->toHaveKey('placement_config')
        ->and($summary['placement_config'])->toBe($config);
});

it('returns safe error message when uploaded PDF is corrupt', function () {
    $user = User::factory()->create();
    $company = createPdfTestCompany('Corrupt PDF Co');
    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    $corruptPdf = UploadedFile::fake()->createWithContent('corrupt.pdf', "%PDF-1.4\nCorrupt internal structure that FPDI cannot parse");

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'name' => 'Corrupt PDF Template',
            'template_format' => 'pdf_overlay',
            'file' => $corruptPdf,
        ]);

    $response->assertSessionHasErrors('file');
    $error = session('errors')->first('file');
    expect($error === 'The uploaded PDF could not be processed. Please verify the file and try again.'
        || $error === 'Unable to read the PDF. The file may be corrupt, damaged, or password-protected.')->toBeTrue();
});

it('supports Inertia redirects for getOrCreateDraft and savePlacements', function () {
    $user = User::factory()->create();
    $company = createPdfTestCompany('Inertia Draft Co');
    grantCompanyPermissions($user, $company, ['documents.templates.update', 'documents.templates.view']);

    $pdfPath = DocumentTemplateStorage::storePdf(
        UploadedFile::fake()->createWithContent('base.pdf', createSamplePdfContent(1)),
        $company->id,
    );

    $template = DocumentGenerationTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Inertia Template',
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay,
        'status' => DocumentGenerationTemplateStatus::Draft,
        'content' => '',
    ]);

    $draft = DocumentGenerationTemplateVersion::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'version' => 1,
        'status' => DocumentGenerationTemplateVersionStatus::Draft,
        'source_pdf_path' => $pdfPath,
        'source_pdf_original_name' => 'base.pdf',
        'source_pdf_size_bytes' => 1000,
        'source_pdf_page_count' => 1,
    ]);

    // getOrCreateDraft via Inertia (without Accept: application/json)
    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.documents.templates'))
        ->post(route('organization.documents.templates.draft', ['template' => $template->id]));

    $response->assertRedirect(route('organization.documents.templates'))
        ->assertSessionHas('success', 'Draft prepared.');

    // savePlacements via Inertia
    $saveResponse = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.documents.templates'))
        ->put(route('organization.documents.templates.versions.placements.save', [
            'template' => $template->id,
            'version' => $draft->id,
        ]), [
            'placements' => [
                [
                    'id' => 'p1',
                    'field' => '{{employee_name}}',
                    'page' => 1,
                    'x' => 0.1,
                    'y' => 0.1,
                    'width' => 0.2,
                    'height' => 0.05,
                ],
            ],
        ]);

    $saveResponse->assertRedirect(route('organization.documents.templates'))
        ->assertSessionHas('success', 'Placements saved.');
});
