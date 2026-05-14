<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function makeDocumentFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'DT1'],
        ['name' => 'Doc Test Land', 'dial_code' => '+900', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'DT1'],
        ['name' => 'Doc Test Currency', 'symbol' => 'D$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'DocCo',
        'slug' => 'docco-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'DOC001',
        'first_name' => 'Test',
        'last_name' => 'Employee',
        'status' => 'active',
    ]);

    return compact('company', 'employee');
}

test('users with permission can upload a document', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type' => 'passport_copy',
        'title' => 'My Passport',
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        'issue_date' => '2020-01-01',
        'expiry_date' => '2030-01-01',
        'document_number' => 'P9876543',
        'notes' => 'Renewed in 2020',
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_documents', [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type' => 'passport_copy',
        'title' => 'My Passport',
        'document_number' => 'P9876543',
        'status' => 'valid',
    ]);
});

test('users without permission cannot upload a document', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type' => 'passport_copy',
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});

test('document status is derived correctly from expiry date', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type' => 'visa',
        'file' => UploadedFile::fake()->create('visa.pdf', 100, 'application/pdf'),
        'expiry_date' => now()->subDay()->toDateString(),
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_documents', [
        'employee_id' => $employee->id,
        'status' => 'expired',
    ]);
});

test('users with permission can edit document metadata', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload']);

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'passport_copy',
        'file_path' => 'employee-documents/test/file.pdf',
        'status' => 'valid',
    ]);

    $this->put("/organization/employees/{$employee->id}/documents/{$doc->id}", [
        'title' => 'Updated Title',
        'document_number' => 'P111',
        'expiry_date' => now()->addYears(5)->toDateString(),
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_documents', [
        'id' => $doc->id,
        'title' => 'Updated Title',
        'document_number' => 'P111',
        'status' => 'valid',
    ]);
});

test('users with permission can delete a document', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.delete']);

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'type' => 'other',
        'document_type' => 'passport_copy',
        'file_path' => 'employee-documents/test/file.pdf',
        'status' => 'valid',
    ]);

    $this->delete("/organization/employees/{$employee->id}/documents/{$doc->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('employee_documents', ['id' => $doc->id]);
});

test('users cannot manage documents for employees in another company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    ['employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload', 'employees.documents.delete']);

    $this->post("/organization/employees/{$otherEmployee->id}/documents", [
        'document_type' => 'visa',
        'file' => UploadedFile::fake()->create('visa.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});
