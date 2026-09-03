<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\DocumentTemplateStorage;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use setasign\Fpdi\Fpdi;
use Spatie\Activitylog\Models\Activity;

function createDocTemplatesSamplePdf(int $pages = 1): string
{
    $fpdi = new Fpdi;
    for ($i = 1; $i <= $pages; $i++) {
        $fpdi->AddPage();
        $fpdi->SetFont('Helvetica', '', 12);
        $fpdi->Write(10, "Page {$i}");
    }

    return (string) $fpdi->Output('S');
}

function createDocTemplatesTestCompany(string $name = 'Test Co'): Company
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

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('templates index lists custom templates for the active company', function () {
    $user = User::factory()->create();
    $companyA = createDocTemplatesTestCompany('Alpha Co');
    $companyB = createDocTemplatesTestCompany('Beta Co');

    grantCompanyPermissions($user, $companyA, ['documents.templates.view']);
    grantCompanyPermissions($user, $companyB, ['documents.templates.view']);

    DocumentGenerationTemplate::factory()->forCompany($companyA)->pdfOverlay()->create([
        'name' => 'Alpha Welcome Letter',
    ]);
    DocumentGenerationTemplate::factory()->forCompany($companyB)->pdfOverlay()->create([
        'name' => 'Beta Welcome Letter',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get(route('organization.documents.templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/templates')
            ->has('custom_templates', 1)
            ->where('custom_templates.0.name', 'Alpha Welcome Letter')
            ->has('merge_fields')
            ->where('can.view_templates', true)
            ->where('can.create_templates', false));
});

test('user without templates access receives 403 on templates route', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates'))
        ->assertForbidden();
});

test('user with only document types view sees empty custom templates without permissions', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    grantCompanyPermissions($user, $company, ['settings.master-data.document-types.view']);

    DocumentGenerationTemplate::factory()->forCompany($company)->create();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/templates')
            ->has('custom_templates', 0)
            ->where('can.view_templates', false)
            ->where('can.create_templates', false));
});

test('templates index excludes legacy content templates and only lists pdf overlay templates', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.view']);

    // Legacy content template (should be excluded from index)
    DocumentGenerationTemplate::factory()->forCompany($company)->content()->create([
        'name' => 'Legacy Content Template',
    ]);

    // PDF template (should be included)
    DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create([
        'name' => 'Active PDF Template',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/templates')
            ->has('custom_templates', 1)
            ->where('custom_templates.0.name', 'Active PDF Template'));
});

test('store creates custom template with pdf upload and opens designer', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    $docType = DocumentType::query()->create(['title' => 'General Notice', 'is_active' => true]);
    Storage::fake(DocumentTemplateStorage::DISK);

    grantCompanyPermissions($user, $company, [
        'documents.templates.view',
        'documents.templates.create',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
            'name' => 'General Welcome Letter',
            'description' => 'Issued to new joiners',
            'document_type_id' => $docType->id,
            'file' => UploadedFile::fake()->createWithContent(
                'welcome.pdf',
                createDocTemplatesSamplePdf(1),
            ),
        ]);

    $template = DocumentGenerationTemplate::query()
        ->where('company_id', $company->id)
        ->where('name', 'General Welcome Letter')
        ->firstOrFail();

    $response->assertRedirect(route('organization.documents.templates.design', $template));
    $response->assertSessionHas('success', 'Template created. Place merge fields on the PDF.');

    $this->assertDatabaseHas('document_generation_templates', [
        'company_id' => $company->id,
        'name' => 'General Welcome Letter',
        'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
        'document_type_id' => $docType->id,
        'status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    // Verify activity logging
    $activity = Activity::forSubject($template)->first();
    expect($activity)->not->toBeNull();
});

test('store rejects content format, raw content, and missing file', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();

    grantCompanyPermissions($user, $company, [
        'documents.templates.create',
    ]);

    $badContent = 'Your bank account is {{bank_account}} and your salary is {{salary}}.';

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'name' => 'Financial Statement',
            'content' => $badContent,
        ]);

    $response->assertSessionHasErrors(['template_format', 'file', 'content']);
    $this->assertDatabaseMissing('document_generation_templates', [
        'name' => 'Financial Statement',
    ]);
});

test('store rejects status field in payload', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    Storage::fake(DocumentTemplateStorage::DISK);

    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
            'name' => 'Draft Letter',
            'file' => UploadedFile::fake()->createWithContent('letter.pdf', createDocTemplatesSamplePdf(1)),
            'status' => 'draft',
        ]);

    $response->assertSessionHasErrors(['status']);
});

test('store rejects duplicate template name in same company but allows in different company', function () {
    $user = User::factory()->create();
    $companyA = createDocTemplatesTestCompany('Company A');
    $companyB = createDocTemplatesTestCompany('Company B');
    Storage::fake(DocumentTemplateStorage::DISK);

    grantCompanyPermissions($user, $companyA, ['documents.templates.create']);
    grantCompanyPermissions($user, $companyB, ['documents.templates.create']);

    DocumentGenerationTemplate::factory()->forCompany($companyA)->pdfOverlay()->create([
        'name' => 'Verification Letter',
    ]);

    // Duplicate in Company A fails
    $responseA = $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->post(route('organization.documents.templates.store'), [
            'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
            'name' => 'Verification Letter',
            'file' => UploadedFile::fake()->createWithContent('v1.pdf', createDocTemplatesSamplePdf(1)),
        ]);

    $responseA->assertSessionHasErrors(['name']);

    // Same name in Company B succeeds
    $responseB = $this->actingAs($user)
        ->withSession(['current_company_id' => $companyB->id])
        ->post(route('organization.documents.templates.store'), [
            'template_format' => DocumentGenerationTemplateFormat::PdfOverlay->value,
            'name' => 'Verification Letter',
            'file' => UploadedFile::fake()->createWithContent('v2.pdf', createDocTemplatesSamplePdf(1)),
        ]);

    $responseB->assertSessionHasNoErrors();
    $this->assertDatabaseHas('document_generation_templates', [
        'company_id' => $companyB->id,
        'name' => 'Verification Letter',
    ]);
});

test('update modifies template attributes and ignores unique rule for self', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->content()->create([
        'name' => 'Old Title',
        'status' => DocumentGenerationTemplateStatus::Draft,
        'content' => 'Historical content for {{employee_name}}',
    ]);
    $originalContent = $template->content;

    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.documents.templates.update', $template), [
            'name' => 'Old Title', // same name, should not trigger duplicate error
            'description' => 'Updated description',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Template updated.');

    $template->refresh();
    expect($template->status)->toBe(DocumentGenerationTemplateStatus::Draft);
    expect($template->description)->toBe('Updated description');
    expect($template->content)->toBe($originalContent);
    expect($template->updated_by)->toBe($user->id);
});

test('update rejects obsolete content mutation', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->content()->create([
        'content' => 'Keep this historical content {{employee_name}}',
    ]);

    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.documents.templates.update', $template), [
            'name' => $template->name,
            'content' => 'Updated content for {{employee_name}}',
        ])
        ->assertSessionHasErrors(['content']);

    expect($template->fresh()->content)->toBe('Keep this historical content {{employee_name}}');
});

test('update rejects status field in payload', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create();

    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.documents.templates.update', $template), [
            'name' => 'Updated Title',
            'status' => 'active',
        ]);

    $response->assertSessionHasErrors(['status']);
});

test('update cannot modify another companys template', function () {
    $user = User::factory()->create();
    $companyA = createDocTemplatesTestCompany('Company A');
    $companyB = createDocTemplatesTestCompany('Company B');

    $templateB = DocumentGenerationTemplate::factory()->forCompany($companyB)->create();

    grantCompanyPermissions($user, $companyA, ['documents.templates.update']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->put(route('organization.documents.templates.update', $templateB), [
            'name' => 'Hacked Name',
        ])
        ->assertNotFound();
});

test('duplicate copies template within company as draft with copy name and logs activity', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();

    $original = DocumentGenerationTemplate::factory()->forCompany($company)->content()->active()->create([
        'name' => 'Travel Authorization',
        'description' => 'Authorizes business travel',
        'content' => 'Dear {{employee_name}}, your travel is approved.',
    ]);

    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    // First duplication -> "Travel Authorization (Copy)"
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.duplicate', $original))
        ->assertRedirect()
        ->assertSessionHas('success', 'Template duplicated.');

    $firstCopy = DocumentGenerationTemplate::query()
        ->where('company_id', $company->id)
        ->where('name', 'Travel Authorization (Copy)')
        ->first();

    expect($firstCopy)->not->toBeNull();
    expect($firstCopy->status)->toBe(DocumentGenerationTemplateStatus::Draft);
    expect($firstCopy->content)->toBe($original->content);
    expect($firstCopy->description)->toBe($original->description);

    // Second duplication -> "Travel Authorization (Copy 2)"
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.duplicate', $original))
        ->assertRedirect();

    $secondCopy = DocumentGenerationTemplate::query()
        ->where('company_id', $company->id)
        ->where('name', 'Travel Authorization (Copy 2)')
        ->first();

    expect($secondCopy)->not->toBeNull();

    // Verify activity logged
    $activity = Activity::where('event', 'duplicated')->where('subject_id', $firstCopy->id)->first();
    expect($activity)->not->toBeNull();
});

test('duplicate cannot duplicate another companys template', function () {
    $user = User::factory()->create();
    $companyA = createDocTemplatesTestCompany('Company A');
    $companyB = createDocTemplatesTestCompany('Company B');

    $templateB = DocumentGenerationTemplate::factory()->forCompany($companyB)->create();

    grantCompanyPermissions($user, $companyA, ['documents.templates.update']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->post(route('organization.documents.templates.duplicate', $templateB))
        ->assertNotFound();
});

test('destroy deletes template belonging to company', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create();

    grantCompanyPermissions($user, $company, ['documents.templates.delete']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.documents.templates.destroy', $template))
        ->assertRedirect()
        ->assertSessionHas('success', 'Template deleted.');

    $this->assertDatabaseMissing('document_generation_templates', [
        'id' => $template->id,
    ]);
});

test('destroy deletes a published template that still points at its published version', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $published = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create([
        'version' => 1,
    ]);
    DocumentGenerationTemplateVersion::factory()->forTemplate($template)->create([
        'version' => 2,
    ]);
    $template->update(['published_version_id' => $published->id]);

    grantCompanyPermissions($user, $company, ['documents.templates.delete']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.documents.templates.destroy', $template))
        ->assertRedirect()
        ->assertSessionHas('success', 'Template deleted.');

    $this->assertDatabaseMissing('document_generation_templates', [
        'id' => $template->id,
    ]);
    $this->assertDatabaseMissing('document_generation_template_versions', [
        'document_generation_template_id' => $template->id,
    ]);
});

test('destroy requires documents templates delete permission', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create();

    grantCompanyPermissions($user, $company, ['documents.templates.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.documents.templates.destroy', $template))
        ->assertForbidden();

    $this->assertDatabaseHas('document_generation_templates', [
        'id' => $template->id,
    ]);
});

test('destroy cannot delete another companys template', function () {
    $user = User::factory()->create();
    $companyA = createDocTemplatesTestCompany('Company A');
    $companyB = createDocTemplatesTestCompany('Company B');

    $templateB = DocumentGenerationTemplate::factory()->forCompany($companyB)->create();

    grantCompanyPermissions($user, $companyA, ['documents.templates.delete']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->delete(route('organization.documents.templates.destroy', $templateB))
        ->assertNotFound();

    $this->assertDatabaseHas('document_generation_templates', [
        'id' => $templateB->id,
    ]);
});

test('preview stored template renders HTML with sample values and does not mutate employee documents', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany('Emirates Shipping');
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'name' => 'Service Letter',
        'content' => "Hello {{employee_name}},\nWelcome to {{company_name}}.",
    ]);

    grantCompanyPermissions($user, $company, ['documents.templates.view']);

    $docCountBefore = EmployeeDocument::query()->count();

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.preview', $template));

    $response->assertOk();
    $data = $response->json();

    expect($data['name'])->toBe('Service Letter');
    expect($data['content_html'])->toContain('Hello Jane Smith,<br />');
    expect($data['content_html'])->toContain('Welcome to Emirates Shipping.');
    expect($data['preview_mode'])->toBe('sample');

    // Confirm no employee documents created or changed
    expect(EmployeeDocument::query()->count())->toBe($docCountBefore);
});

test('preview ignores employee_id and always renders with sample data only', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany('Marine Services');
    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Captain Jack Sparrow',
        'employee_no' => 'CAPT-001',
    ]);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'name' => 'Crew Memo',
        'content' => 'Member: {{employee_name}} ({{employee_no}})',
    ]);

    grantCompanyPermissions($user, $company, ['documents.templates.view']);

    $responseStored = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.preview', [
            'template' => $template->id,
            'employee_id' => $employee->id,
        ]));

    $responseStored->assertOk();
    $dataStored = $responseStored->json();

    expect($dataStored['content_html'])->toContain('Member: Jane Smith (EMP-1042)');
    expect($dataStored['content_html'])->not->toContain('Captain Jack Sparrow');
    expect($dataStored['preview_mode'])->toBe('sample');
});
