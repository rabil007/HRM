<?php

use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('guests cannot access document upload employee search', function () {
    $this->getJson(route('organization.documents.employees.search'))
        ->assertUnauthorized();
});

test('users without documents.upload permission cannot access employee search', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->getJson(route('organization.documents.employees.search', ['q' => 'Test']))
        ->assertForbidden();
});

test('authorized user receives empty array when query is empty or whitespace', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->getJson(route('organization.documents.employees.search'))
        ->assertOk()
        ->assertExactJson([]);

    $this->getJson(route('organization.documents.employees.search', ['q' => '']))
        ->assertOk()
        ->assertExactJson([]);

    $this->getJson(route('organization.documents.employees.search', ['q' => '   ']))
        ->assertOk()
        ->assertExactJson([]);
});

test('authorized user can search active employees by name or employee_no', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'branch' => $branch] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $employeeA = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'EMP-101',
        'name' => 'Alice Smith',
        'status' => 'active',
    ]);

    $employeeB = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'EMP-202',
        'name' => 'Bob Jones',
        'status' => 'active',
    ]);

    // Search by name
    $response = $this->getJson(route('organization.documents.employees.search', ['q' => 'Alice']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment([
            'id' => $employeeA->id,
            'name' => 'Alice Smith',
            'employee_no' => 'EMP-101',
        ]);

    // Search by employee_no
    $this->getJson(route('organization.documents.employees.search', ['q' => '202']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment([
            'id' => $employeeB->id,
            'name' => 'Bob Jones',
            'employee_no' => 'EMP-202',
        ]);
});

test('inactive employees are excluded from search results', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'branch' => $branch] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $active = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'EMP-301',
        'name' => 'Charlie Active',
        'status' => 'active',
    ]);

    $inactive = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'EMP-302',
        'name' => 'Charlie Inactive',
        'status' => 'inactive',
    ]);

    $response = $this->getJson(route('organization.documents.employees.search', ['q' => 'Charlie']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment([
            'id' => $active->id,
            'name' => 'Charlie Active',
        ])
        ->assertJsonMissing([
            'id' => $inactive->id,
            'name' => 'Charlie Inactive',
        ]);
});

test('cross-company employees are excluded from search results', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company1, 'branch' => $branch1] = makeDocumentFixtures();
    ['company' => $company2, 'branch' => $branch2] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company1, ['documents.upload']);

    $empCompany1 = Employee::query()->create([
        'company_id' => $company1->id,
        'branch_id' => $branch1->id,
        'employee_no' => 'EMP-401',
        'name' => 'David CompanyOne',
        'status' => 'active',
    ]);

    $empCompany2 = Employee::query()->create([
        'company_id' => $company2->id,
        'branch_id' => $branch2->id,
        'employee_no' => 'EMP-402',
        'name' => 'David CompanyTwo',
        'status' => 'active',
    ]);

    $response = $this->getJson(route('organization.documents.employees.search', ['q' => 'David']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment([
            'id' => $empCompany1->id,
            'name' => 'David CompanyOne',
        ])
        ->assertJsonMissing([
            'id' => $empCompany2->id,
            'name' => 'David CompanyTwo',
        ]);
});

test('employee visibility scope is respected for department-restricted users', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['documents.upload']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    // Marine employee should be found
    $this->getJson(route('organization.documents.employees.search', ['q' => 'Marine']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment([
            'id' => $marine->id,
            'name' => $marine->name,
        ]);

    // Office employee must be excluded by visibility scope
    $this->getJson(route('organization.documents.employees.search', ['q' => 'Office']))
        ->assertOk()
        ->assertExactJson([]);
});

test('prohibits client company_id in request payload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->getJson(route('organization.documents.employees.search', [
        'q' => 'Alice',
        'company_id' => 99999,
    ]))->assertUnprocessable();
});

test('bulk document upload fails if employee belongs to another company', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company1, 'passportType' => $passportType] = makeDocumentFixtures();
    ['company' => $company2, 'employee' => $crossCompanyEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company1, ['documents.upload']);

    $file = UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf');

    $this->post(route('organization.employees.documents.bulk-store', ['employee' => $crossCompanyEmployee->id]), [
        'documents' => [
            [
                'document_type_id' => $passportType->id,
                'title' => 'Passport',
                'file' => $file,
            ],
        ],
    ])->assertForbidden();
});

test('restricted user cannot bulk upload document to employee outside visibility scope', function () {
    Storage::fake('local');
    Storage::fake('public');

    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $docType = DocumentType::query()->firstOrCreate(
        ['title' => 'Contract Document'],
        ['is_active' => true],
    );

    $file = UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf');

    $this->actingAs($user)
        ->post(route('organization.employees.documents.bulk-store', ['employee' => $office->id]), [
            'documents' => [
                [
                    'document_type_id' => $docType->id,
                    'title' => 'Office Contract',
                    'file' => $file,
                ],
            ],
        ])
        ->assertNotFound();

    expect(EmployeeDocument::query()->where('employee_id', $office->id)->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('restricted user cannot bulk upload to hidden employee even if linked to their user account', function () {
    Storage::fake('local');
    Storage::fake('public');

    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    $office->update(['user_id' => $user->id]);

    grantCompanyPermissions($user, $company, ['documents.upload']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $docType = DocumentType::query()->firstOrCreate(
        ['title' => 'Self Contract Document'],
        ['is_active' => true],
    );

    $file = UploadedFile::fake()->create('self_contract.pdf', 100, 'application/pdf');

    $this->actingAs($user)
        ->post(route('organization.employees.documents.bulk-store', ['employee' => $office->id]), [
            'documents' => [
                [
                    'document_type_id' => $docType->id,
                    'title' => 'Self Contract',
                    'file' => $file,
                ],
            ],
        ])
        ->assertNotFound();

    expect(EmployeeDocument::query()->where('employee_id', $office->id)->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('restricted user can upload document to employee within visibility scope', function () {
    Storage::fake('local');
    Storage::fake('public');

    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine] = makeEmployeeVisibilityFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $docType = DocumentType::query()->firstOrCreate(
        ['title' => 'Allowed Contract'],
        ['is_active' => true],
    );

    $file = UploadedFile::fake()->create('marine_contract.pdf', 100, 'application/pdf');

    $this->actingAs($user)
        ->post(route('organization.employees.documents.bulk-store', ['employee' => $marine->id]), [
            'documents' => [
                [
                    'document_type_id' => $docType->id,
                    'title' => 'Marine Contract',
                    'file' => $file,
                ],
            ],
        ])
        ->assertRedirect();

    expect(EmployeeDocument::query()->where('employee_id', $marine->id)->count())->toBe(1);
    $document = EmployeeDocument::query()->where('employee_id', $marine->id)->first();
    expect($document)->not->toBeNull();
    Storage::disk('local')->assertExists($document->file_path);
});

test('restricted user cannot perform administrative document mutations on hidden employee', function () {
    Storage::fake('local');
    Storage::fake('public');

    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.upload', 'documents.edit', 'documents.delete']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $docType = DocumentType::query()->firstOrCreate(
        ['title' => 'Admin Test Document'],
        ['is_active' => true],
    );

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'document_type_id' => $docType->id,
        'type' => 'other',
        'document_type' => (string) $docType->id,
        'title' => 'Existing Office Doc',
        'file_path' => 'employee-documents/test/office.pdf',
        'status' => 'valid',
    ]);

    // store single
    $file = UploadedFile::fake()->create('new.pdf', 50, 'application/pdf');
    $this->actingAs($user)
        ->post("/organization/employees/{$office->id}/documents", [
            'document_type_id' => $docType->id,
            'title' => 'New Single Doc',
            'file' => $file,
        ])
        ->assertNotFound();

    // update
    $this->actingAs($user)
        ->put("/organization/employees/{$office->id}/documents/{$document->id}", [
            'document_type_id' => $docType->id,
            'title' => 'Hacked Title',
        ])
        ->assertNotFound();

    // replace
    $replaceFile = UploadedFile::fake()->create('replace.pdf', 50, 'application/pdf');
    $this->actingAs($user)
        ->post("/organization/employees/{$office->id}/documents/{$document->id}/replace", [
            'file' => $replaceFile,
        ])
        ->assertNotFound();

    // destroy
    $this->actingAs($user)
        ->delete("/organization/employees/{$office->id}/documents/{$document->id}")
        ->assertNotFound();

    // versions
    $this->actingAs($user)
        ->getJson("/organization/employees/{$office->id}/documents/{$document->id}/versions")
        ->assertNotFound();

    // Verify document was not modified or deleted
    expect($document->fresh())->not->toBeNull();
    expect($document->fresh()->title)->toBe('Existing Office Doc');
});
