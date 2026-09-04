<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Project;
use App\Models\Rank;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentComplianceQuery;
use App\Support\EmployeeDocuments\DocumentRequirementResolver;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('required document with no employee document is missing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_status', 'missing')
            ->where('requirement_summary.missing', 1)
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
            ->where('requirementDocuments.data.0.employee_name', $employee->name)
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

    $this->get('/organization/documents/library?requirement_status=valid')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.status', 'valid')
            ->where('requirementDocuments.data.0.document_type_id', $passportType->id)
        );

    $this->get('/organization/documents/library?requirement_status=expired')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.status', 'expired')
            ->where('requirementDocuments.data.0.document_type_id', $stcw->id)
        );

    $stcwDoc = EmployeeDocument::query()->where('document_type_id', $stcw->id)->first();
    $stcwDoc->update(['expiry_date' => '2026-05-25', 'status' => 'expiring_soon']);

    $this->get('/organization/documents/library?requirement_status=expiring')
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

    $this->get('/organization/documents/library?requirement_status=missing')
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

    $this->get('/organization/documents/library?requirement_status=missing')
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
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$scopes['crew']->id],
        rankIds: [$scopes['captain']->id],
    );

    $employee->update(['department_id' => $scopes['marine']->id, 'rank_id' => null]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('requirementDocuments.data', 0));

    $employee->update(['department_id' => $scopes['crew']->id, 'rank_id' => $scopes['captain']->id]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
        );

    $employee->update(['department_id' => $scopes['marine']->id, 'rank_id' => $scopes['captain']->id]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('requirementDocuments.data', 0));

    $employee->update(['department_id' => $scopes['crew']->id, 'rank_id' => null]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('requirementDocuments.data', 0));
});

test('required for all applies regardless of organizational assignment', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $employee->update(['department_id' => null, 'position_id' => null, 'rank_id' => null]);

    $this->get('/organization/documents/library?requirement_status=missing')
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

    $this->get('/organization/documents/library?requirement_status=expired')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.status', 'expired')
        );

    $this->get('/organization/documents/library?requirement_status=valid')
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

    $this->get('/organization/documents/library')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('employees', 0));

    $this->get('/organization/documents/library?requirement_status=missing')
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

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_summary.missing', 0)
            ->has('requirementDocuments.data', 0)
        );

    $employee->update(['status' => 'terminated']);

    $this->get('/organization/documents/library?requirement_status=missing')
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

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', fn ($id) => $id !== $other['employee']->id)
        );
});

test('employee profile documents tab does not load required document compliance', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view', 'documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('required_documents')
            ->reloadOnly(['documents'], fn (Assert $reload) => $reload
                ->missing('required_documents')
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

    $this->get('/organization/documents/library?requirement_status=required')
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

    $this->get('/organization/documents/library?requirement_status=missing')->assertForbidden();
});

test('newer created_at wins over a higher id on the documents index', function () {
    Carbon::setTestNow('2026-05-20');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'employees.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $newerValid = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/newer-valid.pdf',
        'expiry_date' => '2027-01-01',
        'status' => 'valid',
        'created_at' => '2026-06-10 12:00:00',
        'updated_at' => '2026-06-10 12:00:00',
    ]);

    $olderExpired = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/older-expired.pdf',
        'expiry_date' => '2026-01-01',
        'status' => 'expired',
        'created_at' => '2026-01-10 12:00:00',
        'updated_at' => '2026-01-10 12:00:00',
    ]);

    expect($olderExpired->id)->toBeGreaterThan($newerValid->id);

    $this->get('/organization/documents/library?requirement_status=valid')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.status', 'valid')
            ->where('requirementDocuments.data.0.document_id', $newerValid->id)
        );

    $this->get('/organization/documents/library?requirement_status=expired')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 0)
        );

    Carbon::setTestNow();
});

test('equal created_at ties are broken by the highest id on the documents index', function () {
    Carbon::setTestNow('2026-05-20');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'employees.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $tiedAt = '2026-06-10 12:00:00';

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/tied-valid.pdf',
        'expiry_date' => '2027-01-01',
        'status' => 'valid',
        'created_at' => $tiedAt,
        'updated_at' => $tiedAt,
    ]);

    $higherIdExpired = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/tied-expired.pdf',
        'expiry_date' => '2026-01-01',
        'status' => 'expired',
        'created_at' => $tiedAt,
        'updated_at' => $tiedAt,
    ]);

    $this->get('/organization/documents/library?requirement_status=expired')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.status', 'expired')
            ->where('requirementDocuments.data.0.document_id', $higherIdExpired->id)
        );

    Carbon::setTestNow();
});

test('unmapped null document_type_id rows do not satisfy required document compliance', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);
    makeUnmappedEmployeeDocument($company->id, $employee->id, $passportType->title);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_summary.missing', 1)
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.status', 'missing')
            ->where('requirementDocuments.data.0.document_id', null)
        );
});

test('project scoped missing documents appear in bulk compliance', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType, 'branch' => $branch] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'employees.view']);

    $adnoc = Project::query()->create(['title' => 'ADNOC Bulk '.uniqid(), 'is_active' => true]);
    $otherProject = Project::query()->create(['title' => 'Other Bulk '.uniqid(), 'is_active' => true]);

    Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'PROJ-OTHER-1',
        'name' => 'Other Project Employee',
        'status' => 'active',
        'project_id' => $otherProject->id,
    ]);

    $employee->update(['project_id' => $adnoc->id]);
    makeDocumentRequirement($company->id, $passportType->id, projectIds: [$adnoc->id]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_summary.missing', 1)
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
            ->where('requirementDocuments.data.0.document_type_id', $passportType->id)
            ->where('requirementDocuments.data.0.status', 'missing')
        );
});

test('company a project policy does not create company b missing compliance rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $companyA, 'employee' => $employeeA, 'passportType' => $passportType] = makeDocumentFixtures();
    $other = makeDocumentFixtures();
    grantCompanyPermissions($user, $companyA, ['documents.view']);

    $adnoc = Project::query()->create(['title' => 'ADNOC Cross '.uniqid(), 'is_active' => true]);
    $employeeA->update(['project_id' => $adnoc->id]);
    $other['employee']->update(['project_id' => $adnoc->id]);

    makeDocumentRequirement($companyA->id, $passportType->id, projectIds: [$adnoc->id]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employeeA->id)
            ->where('requirementDocuments.data.0.employee_id', fn ($id) => $id !== $other['employee']->id)
        );
});

test('crew seafarer captain assigned to another project is not required until project matches', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view', 'employees.view']);
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    $requirement = makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$scopes['crew']->id],
        positionIds: [$scopes['seafarer']->id],
        rankIds: [$scopes['captain']->id],
        projectIds: [$scopes['adnoc']->id],
    );

    $employee->update([
        'department_id' => $scopes['crew']->id,
        'position_id' => $scopes['seafarer']->id,
        'rank_id' => $scopes['captain']->id,
        'project_id' => $scopes['otherProject']->id,
    ]);

    $resolver = new DocumentRequirementResolver;
    $compliance = new DocumentComplianceQuery;
    $freshEmployee = $employee->fresh();

    expect($resolver->matches($freshEmployee, $requirement))->toBeFalse()
        ->and($compliance->itemsForEmployee($freshEmployee))->toHaveCount(0)
        ->and($compliance->summary($company->id)['missing'])->toBe(0)
        ->and($compliance->summary($company->id)['required'])->toBe(0);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_summary.missing', 0)
            ->has('requirementDocuments.data', 0)
        );

    $employee->update(['project_id' => $scopes['adnoc']->id]);
    $matchedEmployee = $employee->fresh();

    expect($resolver->matches($matchedEmployee, $requirement))->toBeTrue()
        ->and($compliance->itemsForEmployee($matchedEmployee))->toHaveCount(1)
        ->and($compliance->summary($company->id)['missing'])->toBe(1);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_summary.missing', 1)
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
            ->where('requirementDocuments.data.0.document_type_id', $passportType->id)
            ->where('requirementDocuments.data.0.status', 'missing')
        );
});

test('required documents uses and matching across selected categories', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view', 'documents.view']);
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$scopes['crew']->id],
        rankIds: [$scopes['captain']->id],
        projectIds: [$scopes['adnoc']->id],
    );

    $employee->update([
        'department_id' => $scopes['crew']->id,
        'rank_id' => $scopes['chiefEngineer']->id,
        'project_id' => $scopes['adnoc']->id,
    ]);

    $compliance = new DocumentComplianceQuery;

    expect($compliance->itemsForEmployee($employee->fresh()))->toHaveCount(0);

    $employee->update(['rank_id' => $scopes['captain']->id]);

    $matched = $compliance->itemsForEmployee($employee->fresh());

    expect($matched)->toHaveCount(1)
        ->and($matched[0]['document_type_id'])->toBe($passportType->id);
});

test('bulk missing list uses and matching across selected categories', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$scopes['crew']->id],
        positionIds: [$scopes['seafarer']->id],
        rankIds: [$scopes['captain']->id],
        projectIds: [$scopes['adnoc']->id],
    );

    $employee->update([
        'department_id' => $scopes['crew']->id,
        'position_id' => $scopes['seafarer']->id,
        'rank_id' => $scopes['captain']->id,
        'project_id' => $scopes['otherProject']->id,
    ]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_summary.missing', 0)
            ->has('requirementDocuments.data', 0)
        );

    $employee->update(['project_id' => $scopes['adnoc']->id]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement_summary.missing', 1)
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
        );
});

test('changing employee project_id onto and off a selected project updates missing compliance', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    makeDocumentRequirement($company->id, $passportType->id, projectIds: [$scopes['adnoc']->id]);

    $employee->update(['project_id' => $scopes['adnoc']->id]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
        );

    $employee->update(['project_id' => $scopes['otherProject']->id]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('requirementDocuments.data', 0));

    $employee->update(['project_id' => $scopes['adnoc']->id]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
        );
});

test('empty position category does not restrict bulk missing compliance', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$scopes['crew']->id],
        rankIds: [$scopes['captain']->id],
    );

    $employee->update([
        'department_id' => $scopes['crew']->id,
        'position_id' => null,
        'rank_id' => $scopes['captain']->id,
        'project_id' => $scopes['otherProject']->id,
    ]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
        );
});

test('required for all remains required when selected scopes would not match', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    makeDocumentRequirement(
        $company->id,
        $passportType->id,
        requiredForAll: true,
        departmentIds: [$scopes['crew']->id],
        projectIds: [$scopes['adnoc']->id],
    );

    $employee->update([
        'department_id' => $scopes['marine']->id,
        'project_id' => $scopes['otherProject']->id,
    ]);

    $this->get('/organization/documents/library?requirement_status=missing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirementDocuments.data', 1)
            ->where('requirementDocuments.data.0.employee_id', $employee->id)
        );
});
