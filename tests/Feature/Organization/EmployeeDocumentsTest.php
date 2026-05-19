<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

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
        'name' => 'Test Employee',
        'status' => 'active',
    ]);

    $passportType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    $visaType = DocumentType::query()->firstOrCreate(
        ['title' => 'Visa'],
        ['is_active' => true],
    );

    return compact('company', 'employee', 'passportType', 'visaType');
}

test('users with permission can upload a document', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $passportType->id,
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
        'document_type_id' => $passportType->id,
        'document_type' => (string) $passportType->id,
        'original_filename' => 'passport.pdf',
        'mime_type' => 'application/pdf',
        'title' => 'My Passport',
        'document_number' => 'P9876543',
        'status' => 'valid',
    ]);
});

test('upload rejects inactive or unknown document types and unsupported files', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => 999_999,
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('document_type_id');

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $visaType->id,
        'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
    ])->assertSessionHasErrors('file');
});

test('users with permission can bulk upload documents', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents/bulk", [
        'documents' => [
            [
                'document_type_id' => $passportType->id,
                'title' => 'Passport',
                'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
            ],
            [
                'document_type_id' => $visaType->id,
                'title' => 'Visa',
                'file' => UploadedFile::fake()->image('visa.jpg'),
                'expiry_date' => now()->addDays(20)->toDateString(),
            ],
        ],
    ])->assertRedirect();

    expect(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(2);
    $this->assertDatabaseHas('employee_documents', [
        'employee_id' => $employee->id,
        'document_type_id' => $visaType->id,
        'status' => 'expiring_soon',
    ]);
});

test('users without permission cannot upload a document', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $passportType->id,
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});

test('document status is derived correctly from expiry date', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $visaType->id,
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

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload']);

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
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

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.delete']);

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/file.pdf',
        'status' => 'valid',
    ]);

    $this->delete("/organization/employees/{$employee->id}/documents/{$doc->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('employee_documents', ['id' => $doc->id]);
});

test('users with permission can replace a document file and keep version history', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload']);

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/old.pdf',
        'original_filename' => 'old.pdf',
        'mime_type' => 'application/pdf',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    $this->post("/organization/employees/{$employee->id}/documents/{$doc->id}/replace", [
        'file' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $doc->refresh();

    expect($doc->current_version)->toBe(2);
    $this->assertDatabaseHas('employee_document_versions', [
        'employee_document_id' => $doc->id,
        'version' => 1,
        'file_path' => 'employee-documents/test/old.pdf',
    ]);
});

test('document overview supports filters and pagination props', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $visaType->id,
        'type' => 'other',
        'document_type' => (string) $visaType->id,
        'file_path' => 'employee-documents/test/visa.pdf',
        'expiry_date' => now()->subDay()->toDateString(),
        'status' => 'expired',
    ]);

    $this->get('/organization/documents?document_type='.$visaType->id)
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents')
            ->where('filters.document_type', (string) $visaType->id)
            ->has('documents', 1)
            ->has('pagination')
            ->has('filter_options.document_types')
        );
});

test('dashboard includes document compliance stats', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $visaType->id,
        'type' => 'other',
        'document_type' => (string) $visaType->id,
        'file_path' => 'employee-documents/test/visa.pdf',
        'status' => 'expired',
    ]);

    $this->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('document_compliance.expired', 1)
        );
});

test('users cannot manage documents for employees in another company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'visaType' => $visaType] = makeDocumentFixtures();
    ['employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.documents.upload', 'employees.documents.delete']);

    $this->post("/organization/employees/{$otherEmployee->id}/documents", [
        'document_type_id' => $visaType->id,
        'file' => UploadedFile::fake()->create('visa.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});
