<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeDeployment;

test('assignment belongs to a company and employee', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create();

    expect($assignment->company_id)->toBe($company->id)
        ->and($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->company->is($company))->toBeTrue()
        ->and($assignment->employee->is($employee))->toBeTrue();
});

test('assignment can contain multiple ordered phases', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create();

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'sequence' => 2,
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 3,
    ]);

    $ordered = $assignment->phases()->ordered()->get();

    expect($ordered)->toHaveCount(3)
        ->and($ordered->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($ordered->pluck('phase_code')->all())->toBe([
            CrewPhaseCode::TravelIn,
            CrewPhaseCode::PreMobilisation,
            CrewPhaseCode::JoinStandby,
        ]);
});

test('same phase code can occur multiple times in one assignment', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create();

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 3,
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::Training,
        'sequence' => 4,
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 5,
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::ReadyToJoin,
        'sequence' => 6,
    ]);

    $phases = $assignment->phases()->ordered()->get();

    expect($phases)->toHaveCount(4)
        ->and($phases->where('phase_code', CrewPhaseCode::JoinStandby))->toHaveCount(2)
        ->and($phases->pluck('phase_code')->map->value->all())->toBe([
            'p2a',
            'p2b',
            'p2a',
            'p3',
        ]);
});

test('assignments from different companies remain isolated', function () {
    ['employee' => $employeeA, 'company' => $companyA] = makeCrewAssignmentFixtures();
    ['employee' => $employeeB, 'company' => $companyB] = makeCrewAssignmentFixtures();

    CrewAssignment::factory()->forEmployee($employeeA)->create();
    CrewAssignment::factory()->forEmployee($employeeB)->create();

    expect(CrewAssignment::query()->where('company_id', $companyA->id)->count())->toBe(1)
        ->and(CrewAssignment::query()->where('company_id', $companyB->id)->count())->toBe(1)
        ->and(CrewAssignment::query()->where('company_id', $companyA->id)->first()->employee_id)
        ->toBe($employeeA->id);
});

test('enum and datetime casting works', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $joinAt = now()->addDays(5)->startOfMinute();
    $signoffAt = now()->addMonths(3)->startOfMinute();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'status' => CrewAssignmentStatus::Active,
            'planned_join_at' => $joinAt,
            'planned_signoff_at' => $signoffAt,
        ]);

    $assignment->refresh();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->planned_join_at?->equalTo($joinAt))->toBeTrue()
        ->and($assignment->planned_signoff_at?->equalTo($signoffAt))->toBeTrue();

    $phase = CrewAssignmentPhase::factory()
        ->forAssignment($assignment)
        ->create([
            'phase_code' => CrewPhaseCode::Training,
            'status' => CrewPhaseStatus::Active,
            'details' => ['course' => 'BOSIET'],
        ]);

    $phase->refresh();

    expect($phase->phase_code)->toBe(CrewPhaseCode::Training)
        ->and($phase->status)->toBe(CrewPhaseStatus::Active)
        ->and($phase->details)->toBe(['course' => 'BOSIET']);
});

test('soft deletion works for assignments and phases', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();
    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create();

    $assignment->delete();
    $phase->delete();

    expect(CrewAssignment::query()->find($assignment->id))->toBeNull()
        ->and(CrewAssignment::withTrashed()->find($assignment->id))->not->toBeNull()
        ->and(CrewAssignmentPhase::query()->find($phase->id))->toBeNull()
        ->and(CrewAssignmentPhase::withTrashed()->find($phase->id))->not->toBeNull();
});

test('current phase relationship works', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();
    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->active()->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);
    $assignment->refresh();

    expect($assignment->currentPhase?->is($phase))->toBeTrue()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);
});

test('previous and next assignment relationships work', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $previous = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create();

    $next = CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'previous_assignment_id' => $previous->id,
        ]);

    expect($next->previousAssignment?->is($previous))->toBeTrue()
        ->and($previous->nextAssignments)->toHaveCount(1)
        ->and($previous->nextAssignments->first()?->is($next))->toBeTrue();
});

test('phase scopes active and completed work', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();

    CrewAssignmentPhase::factory()->forAssignment($assignment)->active()->create([
        'sequence' => 1,
        'phase_code' => CrewPhaseCode::JoinStandby,
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->completed()->create([
        'sequence' => 2,
        'phase_code' => CrewPhaseCode::Training,
    ]);

    expect($assignment->phases()->active()->count())->toBe(1)
        ->and($assignment->phases()->completed()->count())->toBe(1);
});

test('existing crew deployment functionality remains unaffected', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewDeploymentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.deployments.view',
    ]);

    $deployment = EmployeeDeployment::factory()
        ->forEmployee($employee)
        ->create([
            'joined_date' => '2026-01-10',
            'disembarked_date' => null,
        ]);

    CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'employee_deployment_id' => $deployment->id,
            'source' => 'legacy_deployment',
        ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.show', $deployment))
        ->assertOk();

    expect($deployment->fresh())->not->toBeNull()
        ->and(EmployeeDeployment::query()->whereKey($deployment->id)->exists())->toBeTrue();
});
