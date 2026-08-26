<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\Rank;
use App\Support\EmployeeDocuments\DocumentRequirementResolver;

test('resolver uses or matching across department position and rank', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType, 'branch' => $branch] = makeDocumentFixtures();

    $crew = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew Resolver',
        'code' => 'CRR',
        'status' => 'active',
    ]);
    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Chief Engineer',
        'status' => 'active',
    ]);
    $captain = Rank::query()->create(['name' => 'Captain Resolver '.uniqid(), 'is_active' => true]);

    $requirement = makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$crew->id],
        positionIds: [$position->id],
        rankIds: [$captain->id],
    );

    $resolver = new DocumentRequirementResolver;
    $requirement->load(['departments', 'positions', 'ranks', 'projects']);

    $employee->update(['department_id' => $crew->id, 'position_id' => null, 'rank_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['department_id' => null, 'position_id' => $position->id, 'rank_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['department_id' => null, 'position_id' => null, 'rank_id' => $captain->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['department_id' => null, 'position_id' => null, 'rank_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();
});

test('required for all takes precedence over selected scopes', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $requirement = makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);
    $requirement->load(['departments', 'positions', 'ranks', 'projects']);

    $resolver = new DocumentRequirementResolver;

    expect($resolver->matches($employee, $requirement))->toBeTrue();
});

test('inactive document types are ignored by the resolver', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);
    $passportType->update(['is_active' => false]);

    $requirements = (new DocumentRequirementResolver)->requirementsForEmployee($employee);

    expect($requirements)->toHaveCount(0);
});

test('company scoped requirements do not leak across tenants', function () {
    ['company' => $companyA, 'employee' => $employee, 'passportType' => $passportType, 'branch' => $branch] = makeDocumentFixtures();
    $other = makeDocumentFixtures();

    makeDocumentRequirement($companyA->id, $passportType->id, requiredForAll: true);

    $foreignEmployee = Employee::query()->create([
        'company_id' => $other['company']->id,
        'branch_id' => $other['branch']->id,
        'employee_no' => 'FOR001',
        'name' => 'Foreign Employee',
        'status' => 'active',
    ]);

    $resolver = new DocumentRequirementResolver;

    expect($resolver->requirementsForEmployee($employee))->toHaveCount(1)
        ->and($resolver->requirementsForEmployee($foreignEmployee))->toHaveCount(0);
});

test('project-only requirement matches the employee assigned to that project', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $adnoc = Project::query()->create(['title' => 'ADNOC Match '.uniqid(), 'is_active' => true]);
    $otherProject = Project::query()->create(['title' => 'Other Project '.uniqid(), 'is_active' => true]);

    $requirement = makeDocumentRequirement($company->id, $passportType->id, projectIds: [$adnoc->id]);
    $resolver = new DocumentRequirementResolver;

    $employee->update(['project_id' => $adnoc->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['project_id' => $otherProject->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update(['project_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();
});

test('project scope uses or matching with department and rank', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $crew = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew Project Or',
        'code' => 'CPO',
        'status' => 'active',
    ]);
    $accounts = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Accounts Project Or',
        'code' => 'APO',
        'status' => 'active',
    ]);
    $captain = Rank::query()->create(['name' => 'Captain Project Or '.uniqid(), 'is_active' => true]);
    $adnoc = Project::query()->create(['title' => 'ADNOC Or '.uniqid(), 'is_active' => true]);
    $otherProject = Project::query()->create(['title' => 'Other Or '.uniqid(), 'is_active' => true]);

    $requirement = makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$crew->id],
        rankIds: [$captain->id],
        projectIds: [$adnoc->id],
    );
    $resolver = new DocumentRequirementResolver;

    $employee->update(['department_id' => $accounts->id, 'rank_id' => null, 'project_id' => $adnoc->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['department_id' => $crew->id, 'rank_id' => null, 'project_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['department_id' => $accounts->id, 'rank_id' => $captain->id, 'project_id' => $otherProject->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['department_id' => $accounts->id, 'rank_id' => null, 'project_id' => $otherProject->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();
});

test('required for all still matches regardless of project assignment', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $adnoc = Project::query()->create(['title' => 'ADNOC All '.uniqid(), 'is_active' => true]);
    $requirement = makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);
    $resolver = new DocumentRequirementResolver;

    $employee->update(['project_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['project_id' => $adnoc->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();
});

test('changing employee project_id changes requirement resolution dynamically', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $adnoc = Project::query()->create(['title' => 'ADNOC Dyn '.uniqid(), 'is_active' => true]);
    makeDocumentRequirement($company->id, $passportType->id, projectIds: [$adnoc->id]);
    $resolver = new DocumentRequirementResolver;

    $employee->update(['project_id' => null]);
    expect($resolver->requirementsForEmployee($employee->fresh()))->toHaveCount(0);

    $employee->update(['project_id' => $adnoc->id]);
    expect($resolver->requirementsForEmployee($employee->fresh()))->toHaveCount(1);

    $employee->update(['project_id' => null]);
    expect($resolver->requirementsForEmployee($employee->fresh()))->toHaveCount(0);
});

test('company a project policy does not apply to company b employees with the same global project', function () {
    ['company' => $companyA, 'employee' => $companyAEmployee, 'passportType' => $passportType] = makeDocumentFixtures();
    $other = makeDocumentFixtures();
    $adnoc = Project::query()->create(['title' => 'ADNOC Tenant '.uniqid(), 'is_active' => true]);

    makeDocumentRequirement($companyA->id, $passportType->id, projectIds: [$adnoc->id]);

    $companyAEmployee->update(['project_id' => $adnoc->id]);
    $companyBEmployee = Employee::query()->create([
        'company_id' => $other['company']->id,
        'branch_id' => $other['branch']->id,
        'employee_no' => 'PROJ-B-1',
        'name' => 'Company B Project Employee',
        'status' => 'active',
        'project_id' => $adnoc->id,
    ]);

    $resolver = new DocumentRequirementResolver;

    expect($resolver->requirementsForEmployee($companyAEmployee->fresh()))->toHaveCount(1)
        ->and($resolver->requirementsForEmployee($companyBEmployee))->toHaveCount(0);
});
