<?php

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access document browse pages', function () {
    $this->get('/organization/documents')->assertRedirect(route('login'));
});

test('users with employees view but without documents view cannot access documents module', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get('/organization/documents')->assertForbidden();
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

    grantCompanyPermissions($user, $company, ['documents.view']);

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
            ->where('expiry', 'all')
            ->has('summary')
            ->where('summary.total_documents', 2)
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

    grantCompanyPermissions($user, $company, ['documents.view']);

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
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 0)
            ->has('searchDocuments.data', 0)
            ->where('searchDocuments.total', 0)
        );
});

test('documents folder index returns matching files when searching document fields', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'title' => 'Emirates ID Card',
        'document_number' => '784-1990-1234567-8',
        'file_path' => 'employee-documents/test/eid.pdf',
        'original_filename' => 'EID-scan.pdf',
        'status' => 'valid',
    ]);

    $this->get('/organization/documents?search=784-1990')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('search', '784-1990')
            ->has('searchDocuments.data', 1)
            ->where('searchDocuments.data.0.document_number', '784-1990-1234567-8')
            ->where('searchDocuments.data.0.document_name', 'EID-scan.pdf')
            ->has('employees', 1)
        );

    $this->get('/organization/documents?search=Emirates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('searchDocuments.data', 1)
            ->where('searchDocuments.data.0.document_type', $passportType->title)
        );

    $this->get('/organization/documents?search=EID-scan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('searchDocuments.data', 1)
            ->where('searchDocuments.data.0.document_name', 'EID-scan.pdf')
        );
});

test('employee documents browse inertia page returns files with document type label', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

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
        'uploaded_by' => $user->id,
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
            ->where('documents.0.expiry_status', null)
            ->where('documents.0.expiry_label', 'No Expiry')
            ->where('documents.0.uploaded_by', $user->name)
            ->where('documents.0.uploaded_at', fn ($value) => $value !== null)
        );
});

test('documents folder index expiry summary counts only tracked documents', function () {
    Carbon::setTestNow('2026-05-20');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/no-expiry.pdf',
        'status' => 'valid',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/expired.pdf',
        'expiry_date' => '2026-05-10',
        'status' => 'expired',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/expiring.pdf',
        'expiry_date' => '2026-05-25',
        'status' => 'expiring_soon',
    ]);

    $this->get('/organization/documents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_documents', 3)
            ->where('summary.expired', 1)
            ->where('summary.expiring_30', 1)
            ->where('summary.expiring_7', 1)
        );

    Carbon::setTestNow();
});

test('expired filter excludes documents without expiry date', function () {
    Carbon::setTestNow('2026-05-20');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/no-expiry.pdf',
        'status' => 'valid',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/expired.pdf',
        'original_filename' => 'Expired Visa.pdf',
        'expiry_date' => '2026-05-10',
        'status' => 'expired',
    ]);

    $this->get('/organization/documents?expiry=expired')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('expiry', 'expired')
            ->has('complianceDocuments.data', 1)
            ->where('complianceDocuments.data.0.document_name', 'Expired Visa.pdf')
            ->where('complianceDocuments.data.0.expiry_status', 'expired')
        );

    Carbon::setTestNow();
});

test('employee documents browse includes expiry fields when expiry is set', function () {
    Carbon::setTestNow('2026-05-20');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/visa.pdf',
        'original_filename' => 'Visa.pdf',
        'document_number' => '784-1234-5678901-2',
        'expiry_date' => '2026-05-25',
        'status' => 'expiring_soon',
    ]);

    $this->get("/organization/documents/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('documents.0.document_number', '784-1234-5678901-2')
            ->where('documents.0.expiry_date', '2026-05-25')
            ->where('documents.0.expiry_status', 'expiring_7')
            ->where('documents.0.expiry_label', 'Expires in 5 days')
            ->where('summary.total_documents', 1)
            ->where('summary.expiring_7', 1)
        );

    Carbon::setTestNow();
});

test('employee documents browse returns documents newest first', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

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

    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->get("/organization/documents/employees/{$otherEmployee->id}")
        ->assertNotFound();
});
