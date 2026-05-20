<?php

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access document browse api or pages', function () {
    $this->getJson('/api/documents/employees')->assertUnauthorized();
    $this->get('/organization/documents')->assertRedirect(route('login'));
});

test('api documents employees returns only employees with document counts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employeeA, 'passportType' => $passportType] = makeDocumentFixtures();

    $employeeB = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $employeeA->branch_id,
        'employee_no' => 'DOC002',
        'name' => 'Second Employee',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeA->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/a.pdf',
        'status' => 'valid',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeA->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/b.pdf',
        'status' => 'valid',
    ]);

    $response = $this->getJson('/api/documents/employees');

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonFragment([
        'employee_id' => $employeeA->id,
        'employee_name' => $employeeA->name,
        'employee_no' => $employeeA->employee_no,
        'document_count' => 2,
    ]);
    $response->assertJsonMissing(['employee_id' => $employeeB->id]);
});

test('api documents employees supports search', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/passport.pdf',
        'status' => 'valid',
    ]);

    $this->getJson('/api/documents/employees?search=DOC001')
        ->assertOk()
        ->assertJsonCount(1);

    $this->getJson('/api/documents/employees?search=missing')
        ->assertOk()
        ->assertJsonCount(0);
});

test('api documents for employee returns file list', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/visa.pdf',
        'original_filename' => 'Visa.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $response = $this->getJson("/api/documents/employees/{$employee->id}");

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonFragment([
        'document_name' => 'Visa.pdf',
        'document_type' => 'Passport Copy',
    ]);
    expect($response->json('0'))->toHaveKeys([
        'id',
        'document_name',
        'document_type',
        'file_url',
        'uploaded_at',
        'can_preview',
    ]);
});

test('api documents for employee returns 404 when employee is outside company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->getJson("/api/documents/employees/{$otherEmployee->id}")
        ->assertNotFound();
});

test('documents folder index inertia page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/file.pdf',
        'status' => 'valid',
    ]);

    $this->get('/organization/documents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->has('employees', 1)
            ->where('employees.0.employee_id', $employee->id)
            ->where('employees.0.document_count', 1)
        );
});

test('employee documents browse inertia page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/file.pdf',
        'original_filename' => 'Contract.pdf',
        'status' => 'valid',
    ]);

    $this->get("/organization/documents/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/employee')
            ->where('employee.id', $employee->id)
            ->has('documents', 1)
            ->where('documents.0.document_name', 'Contract.pdf')
        );
});
