<?php

use App\Models\Employee;
use App\Support\EmployeeDocuments\DocumentRequirementResolver;

test('resolver requires a match in every selected category', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    $requirement = makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$scopes['crew']->id],
        positionIds: [$scopes['seafarer']->id],
        rankIds: [$scopes['captain']->id],
        projectIds: [$scopes['adnoc']->id],
    );

    $resolver = new DocumentRequirementResolver;

    $employee->update([
        'department_id' => $scopes['crew']->id,
        'position_id' => $scopes['seafarer']->id,
        'rank_id' => $scopes['captain']->id,
        'project_id' => $scopes['adnoc']->id,
    ]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['department_id' => $scopes['crew']->id, 'position_id' => null, 'rank_id' => null, 'project_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update(['department_id' => null, 'position_id' => $scopes['seafarer']->id, 'rank_id' => null, 'project_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update(['department_id' => null, 'position_id' => null, 'rank_id' => $scopes['captain']->id, 'project_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update(['department_id' => null, 'position_id' => null, 'rank_id' => null, 'project_id' => $scopes['adnoc']->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update([
        'department_id' => null,
        'position_id' => null,
        'rank_id' => null,
        'project_id' => null,
    ]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();
});

test('department position rank and project must all match when every category is selected', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);
    $resolver = new DocumentRequirementResolver;

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
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update([
        'department_id' => $scopes['crew']->id,
        'position_id' => $scopes['seafarer']->id,
        'rank_id' => $scopes['chiefEngineer']->id,
        'project_id' => $scopes['adnoc']->id,
    ]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update([
        'department_id' => $scopes['marine']->id,
        'position_id' => $scopes['seafarer']->id,
        'rank_id' => $scopes['captain']->id,
        'project_id' => $scopes['adnoc']->id,
    ]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();
});

test('multiple values in the same category match with or', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);
    $resolver = new DocumentRequirementResolver;

    $requirement = makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$scopes['crew']->id, $scopes['marine']->id],
        rankIds: [$scopes['captain']->id, $scopes['chiefEngineer']->id],
        projectIds: [$scopes['adnoc']->id, $scopes['aramco']->id],
    );

    $employee->update([
        'department_id' => $scopes['marine']->id,
        'position_id' => null,
        'rank_id' => $scopes['chiefEngineer']->id,
        'project_id' => $scopes['aramco']->id,
    ]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update([
        'department_id' => $scopes['crew']->id,
        'rank_id' => $scopes['captain']->id,
        'project_id' => $scopes['adnoc']->id,
    ]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();
});

test('empty position and project categories impose no restriction', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);
    $resolver = new DocumentRequirementResolver;

    $requirement = makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$scopes['crew']->id],
        rankIds: [$scopes['captain']->id],
    );

    $employee->update([
        'department_id' => $scopes['crew']->id,
        'position_id' => null,
        'rank_id' => $scopes['captain']->id,
        'project_id' => null,
    ]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['position_id' => $scopes['seafarer']->id, 'project_id' => $scopes['otherProject']->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();
});

test('selected project with a null employee project_id is not required', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);
    $resolver = new DocumentRequirementResolver;

    $requirement = makeDocumentRequirement(
        $company->id,
        $passportType->id,
        projectIds: [$scopes['adnoc']->id],
    );

    $employee->update(['project_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();
});

test('selected rank with a null employee rank_id is not required', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);
    $resolver = new DocumentRequirementResolver;

    $requirement = makeDocumentRequirement(
        $company->id,
        $passportType->id,
        rankIds: [$scopes['captain']->id],
    );

    $employee->update(['rank_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();
});

test('required for all takes precedence over selected scopes', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $requirement = makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);
    $requirement->load(['departments', 'positions', 'ranks', 'projects']);

    $resolver = new DocumentRequirementResolver;

    expect($resolver->matches($employee, $requirement))->toBeTrue();
});

test('required for all ignores selected-scope mismatches', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    $requirement = makeDocumentRequirement(
        $company->id,
        $passportType->id,
        requiredForAll: true,
        departmentIds: [$scopes['crew']->id],
        positionIds: [$scopes['seafarer']->id],
        rankIds: [$scopes['captain']->id],
        projectIds: [$scopes['adnoc']->id],
    );

    $resolver = new DocumentRequirementResolver;

    $employee->update([
        'department_id' => $scopes['marine']->id,
        'position_id' => null,
        'rank_id' => $scopes['chiefEngineer']->id,
        'project_id' => $scopes['otherProject']->id,
    ]);

    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();
});

test('inactive document types are ignored by the resolver', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);
    $passportType->update(['is_active' => false]);

    $requirements = (new DocumentRequirementResolver)->requirementsForEmployee($employee);

    expect($requirements)->toHaveCount(0);
});

test('company scoped requirements do not leak across tenants', function () {
    ['company' => $companyA, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
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
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    $requirement = makeDocumentRequirement($company->id, $passportType->id, projectIds: [$scopes['adnoc']->id]);
    $resolver = new DocumentRequirementResolver;

    $employee->update(['project_id' => $scopes['adnoc']->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['project_id' => $scopes['otherProject']->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update(['project_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();
});

test('selected scopes use and matching across department rank and project', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);

    $requirement = makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$scopes['crew']->id],
        rankIds: [$scopes['captain']->id],
        projectIds: [$scopes['adnoc']->id],
    );
    $resolver = new DocumentRequirementResolver;

    $employee->update(['department_id' => $scopes['marine']->id, 'rank_id' => null, 'project_id' => $scopes['adnoc']->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update(['department_id' => $scopes['crew']->id, 'rank_id' => null, 'project_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update(['department_id' => $scopes['marine']->id, 'rank_id' => $scopes['captain']->id, 'project_id' => $scopes['otherProject']->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeFalse();

    $employee->update(['department_id' => $scopes['crew']->id, 'rank_id' => $scopes['captain']->id, 'project_id' => $scopes['adnoc']->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();
});

test('required for all still matches regardless of project assignment', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);
    $requirement = makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);
    $resolver = new DocumentRequirementResolver;

    $employee->update(['project_id' => null]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();

    $employee->update(['project_id' => $scopes['adnoc']->id]);
    expect($resolver->matches($employee->fresh(), $requirement))->toBeTrue();
});

test('changing employee project_id changes requirement resolution dynamically', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);
    makeDocumentRequirement($company->id, $passportType->id, projectIds: [$scopes['adnoc']->id]);
    $resolver = new DocumentRequirementResolver;

    $employee->update(['project_id' => $scopes['otherProject']->id]);
    expect($resolver->requirementsForEmployee($employee->fresh()))->toHaveCount(0);

    $employee->update(['project_id' => $scopes['adnoc']->id]);
    expect($resolver->requirementsForEmployee($employee->fresh()))->toHaveCount(1);

    $employee->update(['project_id' => $scopes['otherProject']->id]);
    expect($resolver->requirementsForEmployee($employee->fresh()))->toHaveCount(0);
});

test('company a project policy does not apply to company b employees with the same global project', function () {
    ['company' => $companyA, 'employee' => $companyAEmployee, 'passportType' => $passportType] = makeDocumentFixtures();
    $other = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($companyA->id);

    makeDocumentRequirement($companyA->id, $passportType->id, projectIds: [$scopes['adnoc']->id]);

    $companyAEmployee->update(['project_id' => $scopes['adnoc']->id]);
    $companyBEmployee = Employee::query()->create([
        'company_id' => $other['company']->id,
        'branch_id' => $other['branch']->id,
        'employee_no' => 'PROJ-B-1',
        'name' => 'Company B Project Employee',
        'status' => 'active',
        'project_id' => $scopes['adnoc']->id,
    ]);

    $resolver = new DocumentRequirementResolver;

    expect($resolver->requirementsForEmployee($companyAEmployee->fresh()))->toHaveCount(1)
        ->and($resolver->requirementsForEmployee($companyBEmployee))->toHaveCount(0);
});
