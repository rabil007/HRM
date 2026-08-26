<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Rank;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('required document with no employee document is missing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_status', 'missing')
            ->where('requirement_summary.missing', 1)
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
            ->where('requirementDocuments.data.0.document_type_id', $passportType->id)
            ->where('requirementDocuments.data.0.status', 'missing')
            ->where('requirementDocuments.data.0.document_id', null)
        );
});

test('required valid expired and expiring documents map to compliance statuses', function () {
    Carbon::setTestNow('2026-05-20');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType, 'visaType' => $visaType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $stcw = $visaType;
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);
    makeDocumentRequirement($company->id, $stcw->id, requiredForAll: true);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/valid.pdf',
        'expiry_date' => '2027-01-01',
        'status' => 'valid',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $stcw->id,
        'type' => 'other',
        'document_type' => (string) $stcw->id,
        'file_path' => 'employee-documents/test/expired.pdf',
        'expiry_date' => '2026-05-01',
        'status' => 'expired',
    ]);

    $this->get('/organization/documents?requirement_status=valid')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.status', 'valid')
            ->where('requirementDocuments.data.0.document_type_id', $passportType->id)
        );

    $this->get('/organization/documents?requirement_status=expired')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.status', 'expired')
            ->where('requirementDocuments.data.0.document_type_id', $stcw->id)
        );

    $stcwDoc = EmployeeDocument::query()->where('document_type_id', $stcw->id)->first();
    $stcwDoc->update(['expiry_date' => '2026-05-25', 'status' => 'expiring_soon']);

    $this->get('/organization/documents?requirement_status=expiring')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.status', 'expiring')
        );

    Carbon::setTestNow();
});

test('optional missing documents are not reported', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_summary.required', 0)
            ->has('requirementDocuments.data', 0)
        );
});

test('department scoped requirement does not affect employees in another department', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $accountsEmployee, 'passportType' => $passportType, 'branch' => $branch] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $crew = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew Scope',
        'code' => 'CRS',
        'status' => 'active',
    ]);
    $accounts = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Accounts Scope',
        'code' => 'ACS',
        'status' => 'active',
    ]);

    $accountsEmployee->update(['department_id' => $accounts->id]);

    Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $crew->id,
        'employee_no' => 'DOC-CREW-1',
        'name' => 'Crew Employee',
        'status' => 'active',
    ]);

    makeDocumentRequirement($company->id, $passportType->id, departmentIds: [$crew->id]);

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_name', 'Crew Employee')
        );
});

test('changing employee department or rank changes requirements dynamically', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $crew = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew Dynamic',
        'code' => 'CRD',
        'status' => 'active',
    ]);
    $accounts = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Accounts Dynamic',
        'code' => 'ACD',
        'status' => 'active',
    ]);
    $captain = Rank::query()->create(['name' => 'Captain Dyn '.uniqid(), 'is_active' => true]);

    makeDocumentRequirement($company->id, $passportType->id, departmentIds: [$crew->id], rankIds: [$captain->id]);

    $employee->update(['department_id' => $accounts->id, 'rank_id' => null]);

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('requirementDocuments.data', 0));

    $employee->update(['department_id' => $crew->id]);

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
        );

    $employee->update(['department_id' => $accounts->id, 'rank_id' => $captain->id]);

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
        );
});

test('required for all applies regardless of organizational assignment', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $employee->update(['department_id' => null, 'position_id' => null, 'rank_id' => null]);

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
        );
});

test('superseded older upload does not satisfy the current requirement', function () {
    Carbon::setTestNow('2026-05-20');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/old-valid.pdf',
        'expiry_date' => '2027-01-01',
        'status' => 'valid',
        'created_at' => now()->subDay(),
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/new-expired.pdf',
        'expiry_date' => '2026-05-01',
        'status' => 'expired',
    ]);

    $this->get('/organization/documents?requirement_status=expired')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.status', 'expired')
        );

    $this->get('/organization/documents?requirement_status=valid')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('requirementDocuments.data', 0));

    Carbon::setTestNow();
});

test('active employee with zero uploaded documents appears as missing required documents', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $this->get('/organization/documents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('employees', 0));

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
        );
});

test('inactive employees are excluded from operational requirement compliance', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $employee->update(['status' => 'inactive']);

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_summary.missing', 0)
            ->has('requirementDocuments.data', 0)
        );

    $employee->update(['status' => 'terminated']);

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_summary.missing', 0)
            ->has('requirementDocuments.data', 0)
        );
});

test('company a rules do not apply to company b employees', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $companyA, 'passportType' => $passportType] = makeDocumentFixtures();
    $other = makeDocumentFixtures();
    grantCompanyPermissions($user, $companyA, ['documents.view']);
    makeDocumentRequirement($companyA->id, $passportType->id, requiredForAll: true);

    $this->get('/organization/documents?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', fn ($id) => $id !== $other['employee']->id)
        );
});

test('employee profile documents tab includes required document compliance', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view', 'documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['required_documents'], fn (Assert $reload) => $reload
                ->has('required_documents', 1)
                ->where('required_documents.0.document_type_id', $passportType->id)
                ->where('required_documents.0.status', 'missing')
            )
        );
});

test('same document type matched by department and rank appears once', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $crew = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew Once',
        'code' => 'CRO',
        'status' => 'active',
    ]);
    $captain = Rank::query()->create(['name' => 'Captain Once '.uniqid(), 'is_active' => true]);
    $employee->update(['department_id' => $crew->id, 'rank_id' => $captain->id]);

    makeDocumentRequirement($company->id, $passportType->id, departmentIds: [$crew->id], rankIds: [$captain->id]);

    $this->get('/organization/documents?requirement_status=required')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.document_type_id', $passportType->id)
        );
});

test('users without documents view cannot open requirement compliance', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get('/organization/documents?requirement_status=missing')->assertForbidden();
});
