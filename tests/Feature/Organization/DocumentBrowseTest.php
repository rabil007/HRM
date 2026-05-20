<?php

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access document browse pages', function () {
    $this->get('/organization/documents')->assertRedirect(route('login'));
});

test('documents folder index returns only employees with document counts', function () {
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

    $this->get('/organization/documents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->has('employees', 1)
            ->where('employees.0.employee_id', $employeeA->id)
            ->where('employees.0.employee_name', $employeeA->name)
            ->where('employees.0.employee_no', $employeeA->employee_no)
            ->where('employees.0.document_count', 2)
            ->missing('employees.1')
        );

    expect(collect($employeeB->documents)->count())->toBe(0);
});

test('documents folder index supports search', function () {
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

    $this->get('/organization/documents?search=DOC001')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('search', 'DOC001')
        );

    $this->get('/organization/documents?search=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('employees', 0));
});

test('employee documents browse inertia page returns files with document type label', function () {
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
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->get("/organization/documents/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/employee')
            ->where('employee.id', $employee->id)
            ->has('documents', 1)
            ->where('documents.0.document_name', 'Contract.pdf')
            ->where('documents.0.document_type', 'Passport Copy')
            ->where('documents.0.can_preview', true)
        );
});

test('employee documents browse returns documents newest first', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $older = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/older.pdf',
        'original_filename' => 'Older.pdf',
        'status' => 'valid',
        'created_at' => now()->subDay(),
    ]);

    $newer = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/newer.pdf',
        'original_filename' => 'Newer.pdf',
        'status' => 'valid',
        'created_at' => now(),
    ]);

    $this->get("/organization/documents/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('documents.0.id', $newer->id)
            ->where('documents.1.id', $older->id)
        );
});

test('employee documents browse returns 404 when employee is outside company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    ['employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/documents/employees/{$otherEmployee->id}")
        ->assertNotFound();
});
