<?php

use App\Enums\DocumentGenerationTemplateStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

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

    DocumentGenerationTemplate::factory()->forCompany($companyA)->create([
        'name' => 'Alpha Welcome Letter',
    ]);
    DocumentGenerationTemplate::factory()->forCompany($companyB)->create([
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

test('store creates custom template with valid allowed merge fields', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();
    $docType = DocumentType::query()->create(['title' => 'General Notice', 'is_active' => true]);

    grantCompanyPermissions($user, $company, [
        'documents.templates.view',
        'documents.templates.create',
    ]);

    $content = "To {{employee_name}} ({{employee_no}}),\nWelcome to {{company_name}} in {{department_name}} on {{today}}.";

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'name' => 'General Welcome Letter',
            'description' => 'Issued to new joiners',
            'document_type_id' => $docType->id,
            'content' => $content,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Template created.');

    $this->assertDatabaseHas('document_generation_templates', [
        'company_id' => $company->id,
        'name' => 'General Welcome Letter',
        'document_type_id' => $docType->id,
        'status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    // Verify activity logging
    $template = DocumentGenerationTemplate::query()->where('name', 'General Welcome Letter')->firstOrFail();
    $activity = Activity::forSubject($template)->first();

    expect($activity)->not->toBeNull();
    // Verify document content is NOT logged to activity properties
    expect($activity->properties->toArray())->not->toHaveKey('attributes.content');
});

test('store rejects content with unsupported or forbidden merge fields', function () {
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

    $response->assertSessionHasErrors(['content']);
    $this->assertDatabaseMissing('document_generation_templates', [
        'name' => 'Financial Statement',
    ]);
});

test('store rejects status field in payload', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();

    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.templates.store'), [
            'name' => 'Draft Letter',
            'content' => 'Hello {{employee_name}}',
            'status' => 'draft',
        ]);

    $response->assertSessionHasErrors(['status']);
});

test('store rejects duplicate template name in same company but allows in different company', function () {
    $user = User::factory()->create();
    $companyA = createDocTemplatesTestCompany('Company A');
    $companyB = createDocTemplatesTestCompany('Company B');

    grantCompanyPermissions($user, $companyA, ['documents.templates.create']);
    grantCompanyPermissions($user, $companyB, ['documents.templates.create']);

    DocumentGenerationTemplate::factory()->forCompany($companyA)->create([
        'name' => 'Verification Letter',
    ]);

    // Duplicate in Company A fails
    $responseA = $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->post(route('organization.documents.templates.store'), [
            'name' => 'Verification Letter',
            'content' => 'Hello {{employee_name}}',
        ]);

    $responseA->assertSessionHasErrors(['name']);

    // Same name in Company B succeeds
    $responseB = $this->actingAs($user)
        ->withSession(['current_company_id' => $companyB->id])
        ->post(route('organization.documents.templates.store'), [
            'name' => 'Verification Letter',
            'content' => 'Hello {{employee_name}}',
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
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'name' => 'Old Title',
        'status' => DocumentGenerationTemplateStatus::Draft,
    ]);

    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.documents.templates.update', $template), [
            'name' => 'Old Title', // same name, should not trigger duplicate error
            'description' => 'Updated description',
            'content' => 'Updated content for {{employee_name}}',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Template updated.');

    $template->refresh();
    expect($template->status)->toBe(DocumentGenerationTemplateStatus::Draft);
    expect($template->description)->toBe('Updated description');
    expect($template->content)->toBe('Updated content for {{employee_name}}');
    expect($template->updated_by)->toBe($user->id);
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
            'content' => 'Some content',
        ])
        ->assertNotFound();
});

test('duplicate copies template within company as draft with copy name and logs activity', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany();

    $original = DocumentGenerationTemplate::factory()->forCompany($company)->active()->create([
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

test('preview draft renders without persisting template', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany('Gulf Maritime');

    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    $initialCount = DocumentGenerationTemplate::query()->count();

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.preview-draft'), [
            'name' => 'Draft Memo',
            'content' => 'Dear {{employee_name}} at {{company_name}}, placeholder {{unresolved_code}}.',
        ]);

    $response->assertOk();
    $data = $response->json();

    expect($data['content_html'])->toContain('Dear Jane Smith at Gulf Maritime, placeholder {{unresolved_code}}.');
    expect($data['unresolved_placeholders'])->toContain('{{unresolved_code}}');
    expect($data['preview_mode'])->toBe('sample');

    expect(DocumentGenerationTemplate::query()->count())->toBe($initialCount);
});

test('preview draft allows update-only user to preview draft edits', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany('Update Only Maritime');

    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.preview-draft'), [
            'name' => 'Draft Edit Preview',
            'content' => 'Updated draft content for {{employee_name}}.',
        ]);

    $response->assertOk();
    $data = $response->json();

    expect($data['content_html'])->toContain('Updated draft content for Jane Smith.');
    expect($data['preview_mode'])->toBe('sample');
});

test('preview draft rejects users without create or update permissions', function () {
    $user = User::factory()->create();
    $company = createDocTemplatesTestCompany('Restricted Co');

    grantCompanyPermissions($user, $company, ['documents.templates.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.preview-draft'), [
            'name' => 'Unauthorized Draft Preview',
            'content' => 'Hello {{employee_name}}',
        ])
        ->assertForbidden();
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

    // Stored template preview ignores employee_id
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

    // Draft preview also ignores employee_id
    grantCompanyPermissions($user, $company, ['documents.templates.create']);

    $responseDraft = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.documents.templates.preview-draft'), [
            'name' => 'Draft Memo',
            'content' => 'Member: {{employee_name}} ({{employee_no}})',
            'employee_id' => $employee->id,
        ]);

    $responseDraft->assertOk();
    $dataDraft = $responseDraft->json();

    expect($dataDraft['content_html'])->toContain('Member: Jane Smith (EMP-1042)');
    expect($dataDraft['content_html'])->not->toContain('Captain Jack Sparrow');
    expect($dataDraft['preview_mode'])->toBe('sample');
});
