<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\DocumentTemplateMergeFields;
use Database\Seeders\PermissionsSeeder;

function createDesignPreviewCompany(string $name = 'Design Preview Co'): Company
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

test('designers with employee view can search company employees for canvas preview', function () {
    $user = User::factory()->create();
    $company = createDesignPreviewCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update', 'employees.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $match = Employee::factory()->forCompany($company)->create([
        'name' => 'Mohammed Rabil',
        'employee_no' => 'EMP-1042',
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($company)->create([
        'name' => 'Other Person',
        'employee_no' => 'EMP-2001',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.design-employees', [
            'template' => $template,
            'q' => 'Rabil',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'employees')
        ->assertJsonPath('employees.0.id', $match->id)
        ->assertJsonPath('employees.0.name', 'Mohammed Rabil')
        ->assertJsonMissingPath('employees.0.work_email')
        ->assertJsonMissingPath('employees.0.phone');
});

test('design employee search requires update and employees view', function () {
    $user = User::factory()->create();
    $company = createDesignPreviewCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.design-employees', $template))
        ->assertForbidden();
});

test('design employee search is isolated to the current company', function () {
    $user = User::factory()->create();
    $company = createDesignPreviewCompany('Home Co');
    $other = createDesignPreviewCompany('Other Co');
    grantCompanyPermissions($user, $company, ['documents.templates.update', 'employees.view']);

    $homeTemplate = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $foreignTemplate = DocumentGenerationTemplate::factory()->forCompany($other)->pdfOverlay()->create();
    Employee::factory()->forCompany($other)->create([
        'name' => 'Foreign Employee',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.design-employees', [
            'template' => $foreignTemplate,
            'q' => 'Foreign',
        ]))
        ->assertNotFound();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.design-employees', [
            'template' => $homeTemplate,
            'q' => 'Foreign',
        ]))
        ->assertOk()
        ->assertJsonCount(0, 'employees');
});

test('design employee values require employees view', function () {
    $user = User::factory()->create();
    $company = createDesignPreviewCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.design-employees.show', [
            'template' => $template,
            'employee' => $employee,
        ]))
        ->assertForbidden();
});

test('design employee values return only allowlisted merge fields', function () {
    $user = User::factory()->create();
    $company = createDesignPreviewCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update', 'employees.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Jane Smith',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.design-employees.show', [
            'template' => $template,
            'employee' => $employee,
        ]))
        ->assertOk()
        ->assertJsonPath('id', $employee->id)
        ->assertJsonPath('name', 'Jane Smith');

    $values = $response->json('values');
    expect($values)->toBeArray()
        ->and(array_keys($values))->toEqualCanonicalizing(DocumentTemplateMergeFields::allowedKeys())
        ->and($values)->not->toHaveKey('{{passport_number}}')
        ->and($values)->not->toHaveKey('{{salary}}')
        ->and($values['{{employee_name}}'])->toBe('Jane Smith');
});

test('design employee values reject a cross-company employee', function () {
    $user = User::factory()->create();
    $company = createDesignPreviewCompany('Home Co');
    $other = createDesignPreviewCompany('Other Co');
    grantCompanyPermissions($user, $company, ['documents.templates.update', 'employees.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $foreign = Employee::factory()->forCompany($other)->create(['status' => 'active']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.design-employees.show', [
            'template' => $template,
            'employee' => $foreign,
        ]))
        ->assertNotFound();
});

test('design employee values reject an inactive company employee', function () {
    $user = User::factory()->create();
    $company = createDesignPreviewCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update', 'employees.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->pdfOverlay()->create();
    $inactive = Employee::factory()->forCompany($company)->inactive()->create();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.design-employees.show', [
            'template' => $template,
            'employee' => $inactive,
        ]))
        ->assertNotFound();
});

test('content templates cannot use the overlay design employee preview', function () {
    $user = User::factory()->create();
    $company = createDesignPreviewCompany();
    grantCompanyPermissions($user, $company, ['documents.templates.update', 'employees.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'template_format' => DocumentGenerationTemplateFormat::Content,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('organization.documents.templates.design-employees', $template))
        ->assertNotFound();
});
