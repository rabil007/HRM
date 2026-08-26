<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
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
    $requirement->load(['departments', 'positions', 'ranks']);

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
    $requirement->load(['departments', 'positions', 'ranks']);

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
