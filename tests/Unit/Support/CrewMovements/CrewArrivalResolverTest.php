<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewMovements\CrewArrivalResolver;

test('crew arrival resolver ignores foreign company p2a phase in memory', function () {
    ['company' => $localCompany, 'employee' => $employee] = makeCrewAssignmentFixtures();
    ['company' => $foreignCompany] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'company_id' => $localCompany->id,
            'started_at' => '2026-06-01 08:00:00',
        ]);

    // Deliberately attach a foreign-company P2A phase to this local assignment
    CrewAssignmentPhase::factory()->create([
        'crew_assignment_id' => $assignment->id,
        'company_id' => $foreignCompany->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-06-05 09:00:00',
    ]);

    expect(CrewArrivalResolver::timestamp($assignment->fresh(['phases'])))->toBeNull()
        ->and(CrewArrivalResolver::date($assignment->fresh(['phases']), 'Asia/Dubai'))->toBeNull();
});

test('crew arrival resolver ignores foreign company completed legacy p1 phase in memory', function () {
    ['company' => $localCompany, 'employee' => $employee] = makeCrewAssignmentFixtures();
    ['company' => $foreignCompany] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'company_id' => $localCompany->id,
            'started_at' => '2026-06-01 08:00:00',
        ]);

    // Deliberately attach a foreign-company completed legacy P1 phase
    CrewAssignmentPhase::factory()->create([
        'crew_assignment_id' => $assignment->id,
        'company_id' => $foreignCompany->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-06-03 12:00:00',
    ]);

    expect(CrewArrivalResolver::timestamp($assignment->fresh(['phases'])))->toBeNull()
        ->and(CrewArrivalResolver::date($assignment->fresh(['phases']), 'Asia/Dubai'))->toBeNull();
});

test('crew arrival resolver sql filter and order by ignore foreign company phases', function () {
    ['company' => $localCompany, 'employee' => $employee] = makeCrewAssignmentFixtures();
    ['company' => $foreignCompany] = makeCrewAssignmentFixtures();

    // Assignment 1 has only a foreign P2A phase with date 2026-06-10
    $assignment1 = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'company_id' => $localCompany->id,
            'assignment_no' => 'CA-TENANT-FOREIGN-P2A',
            'started_at' => '2026-06-01 08:00:00',
        ]);
    CrewAssignmentPhase::factory()->create([
        'crew_assignment_id' => $assignment1->id,
        'company_id' => $foreignCompany->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-06-10 08:00:00',
    ]);

    // Assignment 2 has a legitimate local P2A phase with date 2026-07-20
    $assignment2 = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'company_id' => $localCompany->id,
            'assignment_no' => 'CA-TENANT-LOCAL-P2A',
            'started_at' => '2026-07-01 08:00:00',
        ]);
    CrewAssignmentPhase::factory()->create([
        'crew_assignment_id' => $assignment2->id,
        'company_id' => $localCompany->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-07-20 08:00:00',
    ]);

    // Assignment 3 has only a foreign completed P1 phase with date 2026-05-15
    $assignment3 = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'company_id' => $localCompany->id,
            'assignment_no' => 'CA-TENANT-FOREIGN-P1',
            'started_at' => '2026-05-01 08:00:00',
        ]);
    CrewAssignmentPhase::factory()->create([
        'crew_assignment_id' => $assignment3->id,
        'company_id' => $foreignCompany->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-05-10 08:00:00',
        'actual_end_at' => '2026-05-15 12:00:00',
    ]);

    $baseQuery = CrewAssignment::query()->where('company_id', $localCompany->id);

    // Filter by foreign P2A date: must return 0 results
    $filteredForeignP2a = CrewArrivalResolver::applyDateFilter(clone $baseQuery, '2026-06-10', '2026-06-10')->get();
    expect($filteredForeignP2a)->toBeEmpty();

    // Filter by foreign P1 date: must return 0 results
    $filteredForeignP1 = CrewArrivalResolver::applyDateFilter(clone $baseQuery, '2026-05-15', '2026-05-15')->get();
    expect($filteredForeignP1)->toBeEmpty();

    // Filter by local P2A date: must find Assignment 2
    $filteredLocal = CrewArrivalResolver::applyDateFilter(clone $baseQuery, '2026-07-20', '2026-07-20')->get();
    expect($filteredLocal)->toHaveCount(1)
        ->and($filteredLocal->first()->assignment_no)->toBe('CA-TENANT-LOCAL-P2A');

    // Sorting: Assignment 2 has resolved arrival 2026-07-20; Assignment 1 and 3 resolve to NULL arrival
    $orderedDesc = CrewArrivalResolver::applyOrderBy(clone $baseQuery, 'desc')->get();
    expect($orderedDesc->first()->assignment_no)->toBe('CA-TENANT-LOCAL-P2A');
});

test('crew arrival resolver preserves same company p2a precedence over completed p1', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'company_id' => $company->id,
            'started_at' => '2026-06-01 08:00:00',
        ]);

    CrewAssignmentPhase::factory()->create([
        'crew_assignment_id' => $assignment->id,
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-06-03 12:00:00',
    ]);
    CrewAssignmentPhase::factory()->create([
        'crew_assignment_id' => $assignment->id,
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-06-15 09:00:00',
    ]);

    expect(CrewArrivalResolver::timestamp($assignment->fresh(['phases']))?->format('Y-m-d H:i:s'))
        ->toBe('2026-06-15 09:00:00')
        ->and(CrewArrivalResolver::date($assignment->fresh(['phases']), 'UTC'))
        ->toBe('2026-06-15');

    $filtered = CrewArrivalResolver::applyDateFilter(
        CrewAssignment::query()->where('company_id', $company->id),
        '2026-06-15',
        '2026-06-15',
    )->get();
    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()->id)->toBe($assignment->id);
});

test('crew arrival resolver uses same company completed p1 as fallback when no p2a exists', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'company_id' => $company->id,
            'started_at' => '2026-04-01 08:00:00',
        ]);

    CrewAssignmentPhase::factory()->create([
        'crew_assignment_id' => $assignment->id,
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-04-01 08:00:00',
        'actual_end_at' => '2026-04-05 14:00:00',
    ]);

    expect(CrewArrivalResolver::timestamp($assignment->fresh(['phases']))?->format('Y-m-d H:i:s'))
        ->toBe('2026-04-05 14:00:00')
        ->and(CrewArrivalResolver::date($assignment->fresh(['phases']), 'UTC'))
        ->toBe('2026-04-05');

    $filtered = CrewArrivalResolver::applyDateFilter(
        CrewAssignment::query()->where('company_id', $company->id),
        '2026-04-05',
        '2026-04-05',
    )->get();
    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()->id)->toBe($assignment->id);
});
