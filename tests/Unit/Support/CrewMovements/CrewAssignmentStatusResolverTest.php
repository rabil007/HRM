<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use Carbon\CarbonImmutable;

test('forEmployee returns available when no assignment exists', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $resolver = new CrewAssignmentStatusResolver;
    $result = $resolver->forEmployee($employee);

    expect($result['status'])->toBe('in_home')
        ->and($result['label'])->toBe('Available')
        ->and($result['assignment_id'])->toBeNull()
        ->and($result['current_phase'])->toBeNull()
        ->and($result['current_vessel'])->toBeNull();
});

test('forEmployee returns pre mobilisation for draft assignment', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Draft Vessel');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-DRAFT',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'started_at' => null,
        'source' => 'manual',
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'sequence' => 0,
        'status' => CrewPhaseStatus::Planned,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    $resolver = new CrewAssignmentStatusResolver;
    $result = $resolver->forEmployee($employee->fresh());

    expect($result['status'])->toBe('pre_mobilisation')
        ->and($result['label'])->toBe('Pre-mobilisation')
        ->and($result['current_phase'])->toBe('p0');
});

test('forEmployee returns phase-based status for each phase code', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Phase Status Vessel');

    $phaseMap = [
        CrewPhaseCode::TravelIn => ['travel_in', 'Travel in'],
        CrewPhaseCode::JoinStandby => ['join_standby', 'Join standby'],
        CrewPhaseCode::Training => ['training', 'Training'],
        CrewPhaseCode::ReadyToJoin => ['ready_to_join', 'Ready to join'],
        CrewPhaseCode::OnVessel => ['on_vessel', 'On vessel'],
        CrewPhaseCode::DemobStandby => ['demob_standby', 'Demob standby'],
        CrewPhaseCode::HomeRedeploy => ['home_redeploy', 'Home / redeploy'],
    ];

    foreach ($phaseMap as $code => [$expectedStatus, $expectedLabel]) {
        $assignment = CrewAssignment::query()->create([
            'company_id' => $company->id,
            'assignment_no' => 'CA-'.now()->year.'-'.Str\Str::upper(Str\Str::random(6)),
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'status' => CrewAssignmentStatus::Active,
            'started_at' => CarbonImmutable::today()->subDays(5),
            'source' => 'manual',
        ]);

        $phase = CrewAssignmentPhase::query()->create([
            'company_id' => $company->id,
            'crew_assignment_id' => $assignment->id,
            'phase_code' => $code,
            'sequence' => 1,
            'status' => CrewPhaseStatus::Active,
            'actual_start_at' => CarbonImmutable::today()->subDays(2),
        ]);

        $assignment->update(['current_phase_id' => $phase->id]);

        $resolver = new CrewAssignmentStatusResolver;
        $result = $resolver->forEmployee($employee->fresh());

        expect($result['status'])->toBe($expectedStatus)
            ->and($result['label'])->toBe($expectedLabel)
            ->and($result['assignment_id'])->toBe($assignment->id);

        CrewAssignment::query()->whereKey($assignment->id)->delete();
    }
});

test('forEmployee returns in home with days when assignment is completed', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Completed Vessel');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-COMPLETED',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::today()->subDays(15),
        'closed_at' => CarbonImmutable::today()->subDays(5),
        'source' => 'manual',
    ]);

    $resolver = new CrewAssignmentStatusResolver;
    $result = $resolver->forEmployee($employee->fresh());

    expect($result['status'])->toBe('in_home')
        ->and($result['label'])->toBe('In home · 6d')
        ->and($result['in_home_days'])->toBe(6);
});

test('forEmployee returns needs update when active assignment has no current phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Broken Vessel');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-BROKEN',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => CarbonImmutable::today()->subDays(5),
        'source' => 'manual',
        'current_phase_id' => null,
    ]);

    $resolver = new CrewAssignmentStatusResolver;
    $result = $resolver->forEmployee($employee->fresh());

    expect($result['status'])->toBe('movement_update_required')
        ->and($result['label'])->toBe('Needs update')
        ->and($result['warning'])->toBe('Active assignment has no current phase.');
});

test('forEmployee exposes current vessel name for on vessel phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Vessel Name Test');

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $resolver = new CrewAssignmentStatusResolver;
    $result = $resolver->forEmployee($employee->fresh());

    expect($result['current_vessel'])->toBe('Vessel Name Test')
        ->and($result['vessel_name'])->toBe('Vessel Name Test');
});

test('forEmployee prefers active assignment over draft', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $firstVessel = makeCrewMovementVessel('Draft Vessel');
    $secondVessel = makeCrewMovementVessel('Active Vessel');

    $draftAssignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-DRAFT',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $firstVessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'source' => 'manual',
        'created_at' => now()->addMinute(),
    ]);

    $activeAssignment = makeActiveOnVesselAssignment($company, $employee, $rank, $secondVessel);

    $resolver = new CrewAssignmentStatusResolver;
    $result = $resolver->forEmployee($employee->fresh());

    expect($result['assignment_id'])->toBe($activeAssignment->id)
        ->and($result['status'])->toBe('on_vessel');
});

test('forEmployees returns map keyed by employee id', function () {
    ['company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Multi Employee Vessel');

    $employeeA = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id]);
    $employeeB = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id]);
    $employeeC = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id]);

    makeActiveOnVesselAssignment($company, $employeeA, $rank, $vessel);

    $resolver = new CrewAssignmentStatusResolver;
    $results = $resolver->forEmployees([$employeeA, $employeeB, $employeeC], $company->id);

    expect($results)->toHaveCount(3)
        ->and($results[$employeeA->id]['status'])->toBe('on_vessel')
        ->and($results[$employeeB->id]['status'])->toBe('in_home')
        ->and($results[$employeeC->id]['status'])->toBe('in_home');
});
