<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\Hotel;
use App\Models\Position;
use App\Models\RoomType;
use App\Models\Vessel;
use App\Support\Reports\CrewMovementHistoryPresenter;
use Carbon\CarbonImmutable;

test('it preserves repeated phases and calculates elapsed whole days in company timezone', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-25 00:30:00', 'Asia/Dubai'));
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'position_id' => $rank->id,
            'status' => CrewAssignmentStatus::Active,
            'started_at' => '2026-07-14 20:00:00',
            'planned_signoff_at' => '2026-08-31 00:00:00',
        ]);

    $phases = [
        [CrewPhaseCode::JoinStandby, 1, '2026-07-15 08:00:00', '2026-07-17 08:00:00', null],
        [CrewPhaseCode::Training, 2, '2026-07-17 08:00:00', '2026-07-19 08:00:00', ['provider' => 'ABC Training', 'course' => 'BOSIET']],
        [CrewPhaseCode::JoinStandby, 3, '2026-07-19 08:00:00', '2026-07-22 08:00:00', null],
        [CrewPhaseCode::Training, 4, '2026-07-22 08:00:00', '2026-07-24 08:00:00', ['provider' => 'XYZ Academy', 'course' => 'Refresher']],
    ];

    foreach ($phases as [$code, $sequence, $start, $end, $details]) {
        CrewAssignmentPhase::factory()
            ->forAssignment($assignment)
            ->create([
                'phase_code' => $code,
                'sequence' => $sequence,
                'status' => CrewPhaseStatus::Completed,
                'actual_start_at' => $start,
                'actual_end_at' => $end,
                'details' => $details,
            ]);
    }

    $onVessel = CrewAssignmentPhase::factory()
        ->forAssignment($assignment)
        ->create([
            'phase_code' => CrewPhaseCode::OnVessel,
            'sequence' => 5,
            'status' => CrewPhaseStatus::Active,
            'actual_start_at' => CarbonImmutable::parse('2026-07-24 20:30:00', 'UTC'),
            'actual_end_at' => null,
        ]);
    $assignment->update(['current_phase_id' => $onVessel->id]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
        ]),
    );

    expect($row['join_standby']['periods'])->toHaveCount(2)
        ->and($row['join_standby']['total_days'])->toBe(5)
        ->and($row['training']['periods'])->toHaveCount(2)
        ->and($row['training']['total_days'])->toBe(4)
        ->and($row['training']['details'])->toBe([
            'ABC Training — BOSIET',
            'XYZ Academy — Refresher',
        ])
        ->and($row['on_vessel']['actual_join'])->toBe('2026-07-24')
        ->and($row['on_vessel']['actual_disembarkation'])->toBeNull()
        ->and($row['on_vessel']['total_days'])->toBe(1)
        ->and($row['payroll_days']['sign_on_standby']['periods'])->toBe([
            [
                'from' => '2026-07-15',
                'to' => '2026-07-23',
                'days' => 9,
            ],
        ])
        ->and($row['payroll_days']['sign_on_standby']['total_days'])->toBe(9)
        ->and($row['payroll_days']['onsite']['periods'])->toBe([
            [
                'from' => '2026-07-24',
                'to' => '2026-07-25',
                'days' => 2,
            ],
        ])
        ->and($row['payroll_days']['onsite']['total_days'])->toBe(2)
        ->and($row['payroll_days']['sign_off_standby']['total_days'])->toBe(0)
        ->and($row['payroll_days']['total_days'])->toBe(11)
        ->and($row['planned_signoff'])->toBe('2026-08-31');

    CarbonImmutable::setTestNow();
});

test('same date activity counts as one payroll calendar day', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-07-25 08:00:00',
        'actual_end_at' => '2026-07-25 18:00:00',
    ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
        ]),
    );

    expect($row['join_standby']['total_days'])->toBe(0)
        ->and($row['payroll_days']['sign_on_standby']['periods'])->toBe([
            [
                'from' => '2026-07-25',
                'to' => '2026-07-25',
                'days' => 1,
            ],
        ])
        ->and($row['payroll_days']['sign_on_standby']['total_days'])->toBe(1)
        ->and($row['payroll_days']['total_days'])->toBe(1);
});

test('it maps every lifecycle phase and keeps planned and actual dates separate', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'planned_join_at' => '2026-01-10',
            'planned_signoff_at' => '2026-02-09',
            'planned_travel_at' => '2026-02-12',
            'started_at' => '2026-01-01',
            'closed_at' => '2026-02-15',
        ]);

    $phaseData = [
        [CrewPhaseCode::PreMobilisation, 1, '2026-01-01', '2026-01-03'],
        [CrewPhaseCode::TravelIn, 2, '2026-01-03', '2026-01-04'],
        [CrewPhaseCode::ReadyToJoin, 3, '2026-01-04', '2026-01-10'],
        [CrewPhaseCode::OnVessel, 4, '2026-01-10', '2026-02-10'],
        [CrewPhaseCode::DemobStandby, 5, '2026-02-10', '2026-02-12'],
        [CrewPhaseCode::HomeRedeploy, 6, '2026-02-12', '2026-02-15'],
    ];

    foreach ($phaseData as [$code, $sequence, $start, $end]) {
        CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
            'phase_code' => $code,
            'sequence' => $sequence,
            'status' => CrewPhaseStatus::Completed,
            'planned_start_at' => $code === CrewPhaseCode::TravelIn ? '2026-01-02' : null,
            'actual_start_at' => $start,
            'actual_end_at' => $end,
        ]);
    }

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
        ]),
    );

    expect($row['planned_travel_in'])->toBe('2026-01-02')
        ->and($row['has_legacy_phases'])->toBeTrue()
        ->and($row['planned_travel_home'])->toBe('2026-02-12')
        ->and($row['pre_mobilisation']['from'])->toBe('2026-01-01')
        ->and($row['travel_in']['to'])->toBe('2026-01-04')
        ->and($row['ready_to_join']['total_days'])->toBe(6)
        ->and($row['on_vessel']['actual_join'])->toBe('2026-01-10')
        ->and($row['on_vessel']['actual_disembarkation'])->toBe('2026-02-10')
        ->and($row['on_vessel']['actual_disembarkation'])->not->toBe($row['planned_signoff'])
        ->and($row['demob_standby']['total_days'])->toBe(2)
        ->and($row['home_redeploy']['total_days'])->toBe(3)
        ->and($row['assignment_closed'])->toBe('2026-02-15')
        ->and($row['total_assignment_days'])->toBe(45)
        ->and($row['payroll_days']['sign_on_standby']['total_days'])->toBe(6)
        ->and($row['payroll_days']['onsite']['total_days'])->toBe(32)
        ->and($row['payroll_days']['sign_off_standby']['total_days'])->toBe(2)
        ->and($row['payroll_days']['total_days'])->toBe(40);
});

test('planned only phases do not report actual duration', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'status' => CrewPhaseStatus::Planned,
        'planned_start_at' => '2026-07-01',
        'planned_end_at' => '2026-07-10',
        'actual_start_at' => null,
        'actual_end_at' => null,
    ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
        ]),
    );

    expect($row['pre_mobilisation']['total_days'])->toBeNull()
        ->and($row['pre_mobilisation']['total_days_label'])->toBe('Not recorded');
});

test('it exposes approved correction metadata without treating pending as official corrections', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();

    CrewMovementCorrection::factory()
        ->forAssignment($assignment)
        ->approved()
        ->create([
            'requested_by' => $user->id,
            'decided_by' => $user->id,
            'decided_at' => '2026-07-10 12:00:00',
        ]);

    CrewMovementCorrection::factory()
        ->forAssignment($assignment)
        ->pending()
        ->create([
            'requested_by' => $user->id,
        ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
            'corrections',
        ]),
    );

    expect($row['has_corrections'])->toBeTrue()
        ->and($row['correction_count'])->toBe(1)
        ->and($row['last_corrected_at'])->toBe('2026-07-10')
        ->and($row['has_pending_corrections'])->toBeTrue();
});

test('modern assignments without legacy phases do not expose legacy placeholders', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'planned_arrival_at' => '2026-08-20',
            'planned_join_at' => '2026-08-22',
        ]);

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-08-10',
        'actual_end_at' => '2026-08-12',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-08-12',
        'actual_end_at' => null,
    ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
        ]),
    );

    expect($row['has_legacy_phases'])->toBeFalse()
        ->and($row['travel_in']['periods'])->toBe([])
        ->and($row['ready_to_join']['periods'])->toBe([])
        ->and($row['planned_arrival'])->toBe('2026-08-20')
        ->and($row['actual_arrival'])->toBe('2026-08-12');
});

test('it exposes tour sign-off override exact timestamps accommodation and linked transfer', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 12:00:00', 'Asia/Dubai'));

    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    $previousVessel = Vessel::factory()->create(['company_id' => $company->id, 'name' => 'Vessel A']);
    $nextVessel = Vessel::factory()->create(['company_id' => $company->id, 'name' => 'Vessel B']);

    $source = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-2026-000041',
            'position_id' => $rank->id,
            'vessel_id' => $previousVessel->id,
            'source' => 'manual',
            'started_at' => '2026-08-01 06:00:00',
            'closed_at' => '2026-09-24 06:30:00',
        ]);

    $destination = CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-2026-000042',
            'position_id' => $rank->id,
            'vessel_id' => $nextVessel->id,
            'source' => 'vessel_transfer',
            'previous_assignment_id' => $source->id,
            'tour_of_duty_days' => 60,
            'planned_signoff_at' => '2026-11-15',
            'planned_signoff_source' => CrewPlannedSignoffSource::ManualOverride,
            'planned_signoff_override_reason' => 'Client requested two-week extension',
            'started_at' => '2026-09-24 06:30:00',
        ]);

    $p4 = CrewAssignmentPhase::factory()->forAssignment($destination)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-09-24 07:30:00',
        'actual_end_at' => null,
    ]);
    $destination->update(['current_phase_id' => $p4->id]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Royal Rose']);
    $roomType = RoomType::factory()->create(['company_id' => $company->id, 'hotel_id' => $hotel->id, 'name' => 'Deluxe']);

    CrewAccommodationStay::factory()->create([
        'crew_assignment_id' => $destination->id,
        'company_id' => $company->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'check_in_date' => '2026-09-10',
        'check_out_date' => '2026-09-14',
    ]);

    CrewAccommodationStay::factory()->create([
        'crew_assignment_id' => $destination->id,
        'company_id' => $company->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
    ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $destination->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
            'accommodationStays.hotel',
            'accommodationStays.roomType',
            'accommodationStays.startedFromPhase',
            'previousAssignment.vessel',
            'previousAssignment.position',
            'previousAssignment.client',
            'previousAssignment.currentPhase',
            'nextAssignments',
        ]),
    );

    expect($row['on_vessel']['actual_join_at'])->toBe('2026-09-24 07:30:00')
        ->and($row['tour']['tour_of_duty_days'])->toBe(60)
        ->and($row['tour']['planned_signoff_source'])->toBe('manual_override')
        ->and($row['tour']['planned_signoff_override_reason'])->toBe('Client requested two-week extension')
        ->and($row['accommodation_stays'])->toHaveCount(2)
        ->and(collect($row['accommodation_stays'])->pluck('accommodation_status')->all())->toContain('hotel', 'no_accommodation')
        ->and($row['linked_assignments']['previous']['assignment_no'])->toBe('CA-2026-000041')
        ->and($row['linked_assignments']['previous']['vessel']['name'])->toBe('Vessel A')
        ->and($row['source'])->toBe('vessel_transfer')
        ->and($row['company_timezone'])->toBe($company->timezone);

    CarbonImmutable::setTestNow();
});

test('it reports repeated training history with employee training link status', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();

    $first = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::Training,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'planned_start_at' => '2026-09-14 08:00:00',
        'planned_end_at' => '2026-09-16 17:00:00',
        'actual_start_at' => '2026-09-14 08:00:00',
        'actual_end_at' => '2026-09-16 16:30:00',
        'details' => ['provider' => 'ABC Training Centre', 'course' => 'BOSIET'],
        'remarks' => 'Refresher required by client',
    ]);

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::Training,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-09-20 08:00:00',
        'actual_end_at' => '2026-09-21 17:00:00',
        'details' => ['provider' => 'XYZ Academy', 'course' => 'HUET'],
    ]);

    EmployeeTraining::factory()
        ->forEmployee($employee)
        ->create([
            'source_crew_assignment_phase_id' => $first->id,
        ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases.employeeTraining.course',
        ]),
    );

    expect($row['training']['history'])->toHaveCount(2)
        ->and($row['training']['history'][0]['provider'])->toBe('ABC Training Centre')
        ->and($row['training']['history'][0]['course'])->toBe('BOSIET')
        ->and($row['training']['history'][0]['remarks'])->toBe('Refresher required by client')
        ->and($row['training']['history'][0]['employee_training_linked'])->toBeTrue()
        ->and($row['training']['history'][1]['employee_training_linked'])->toBeFalse()
        ->and($row['phase_timeline'])->toHaveCount(2)
        ->and($row['phase_timeline'][0]['occurrence'])->toBe(1)
        ->and($row['phase_timeline'][1]['occurrence'])->toBe(2);
});

test('it exposes linked redeployment starting checkpoint separately from current phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $sourceVessel = Vessel::factory()->create(['company_id' => $company->id, 'name' => 'Vessel A']);
    $destinationVessel = Vessel::factory()->create(['company_id' => $company->id, 'name' => 'Vessel B']);

    $source = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-2026-000041',
            'position_id' => $rank->id,
            'vessel_id' => $sourceVessel->id,
            'source' => 'manual',
        ]);
    $sourceP5 = CrewAssignmentPhase::factory()->forAssignment($source)->create([
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-09-20 08:00:00',
        'actual_end_at' => '2026-09-24 06:00:00',
    ]);
    $source->update(['current_phase_id' => $sourceP5->id]);

    $destination = CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-2026-000042',
            'position_id' => $rank->id,
            'vessel_id' => $destinationVessel->id,
            'source' => 'redeployment',
            'previous_assignment_id' => $source->id,
            'started_at' => '2026-09-24 06:30:00',
        ]);

    CrewAssignmentPhase::factory()->forAssignment($destination)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-09-24 07:00:00',
        'actual_end_at' => '2026-09-25 07:00:00',
    ]);
    $current = CrewAssignmentPhase::factory()->forAssignment($destination)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-09-25 08:00:00',
        'actual_end_at' => null,
    ]);
    $destination->update(['current_phase_id' => $current->id]);

    $row = CrewMovementHistoryPresenter::toArray(
        $destination->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
            'previousAssignment.vessel',
            'previousAssignment.position',
            'previousAssignment.client',
            'previousAssignment.currentPhase',
            'previousAssignment.phases',
            'nextAssignments.phases',
            'nextAssignments.currentPhase',
        ]),
    );

    expect($row['starting_phase_code'])->toBe('p2a')
        ->and($row['starting_phase_label'])->toBe('Join Standby')
        ->and($row['current_phase']['code'])->toBe('p4')
        ->and($row['linked_assignments']['previous']['assignment_no'])->toBe('CA-2026-000041')
        ->and($row['linked_assignments']['previous']['source'])->toBe('manual')
        ->and($row['linked_assignments']['previous']['source_label'])->toBe('Manual')
        ->and($row['linked_assignments']['relationship'])->toBe('redeployment')
        ->and($row['linked_assignments']['relationship_label'])->toBe('Redeployment')
        ->and($row['linked_assignments']['previous']['starting_phase_code'])->toBe('p5')
        ->and($row['linked_assignments']['previous']['starting_phase_label'])->toBe('Demobilisation Standby')
        ->and($row['source'])->toBe('redeployment')
        ->and($row['source_label'])->toBe('Redeployment')
        ->and($row['modern_phase_timeline'])->toHaveCount(2)
        ->and($row['legacy_phase_timeline'])->toBe([]);

    $directP4 = CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-2026-000043',
            'position_id' => $rank->id,
            'vessel_id' => $destinationVessel->id,
            'source' => 'redeployment',
            'previous_assignment_id' => $source->id,
        ]);
    $p4 = CrewAssignmentPhase::factory()->forAssignment($directP4)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-09-26 08:00:00',
        'actual_end_at' => null,
    ]);
    $directP4->update(['current_phase_id' => $p4->id]);

    $directRow = CrewMovementHistoryPresenter::toArray(
        $directP4->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
            'previousAssignment.phases',
            'previousAssignment.currentPhase',
            'previousAssignment.vessel',
            'previousAssignment.position',
            'previousAssignment.client',
            'nextAssignments',
        ]),
    );

    expect($directRow['starting_phase_code'])->toBe('p4')
        ->and($directRow['starting_phase_label'])->toBe('On Vessel')
        ->and($directRow['current_phase']['code'])->toBe('p4');
});

test('it separates legacy p1 p3 timeline entries from modern lifecycle', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-01-03 08:00:00',
        'actual_end_at' => '2026-01-04 11:00:00',
        'remarks' => 'Legacy travel',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-01-04 12:00:00',
        'actual_end_at' => '2026-01-05 12:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::ReadyToJoin,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-01-05 12:00:00',
        'actual_end_at' => '2026-01-06 08:00:00',
    ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases']),
    );

    expect($row['has_legacy_phases'])->toBeTrue()
        ->and($row['phase_timeline'])->toHaveCount(3)
        ->and(collect($row['modern_phase_timeline'])->pluck('phase_code')->all())->toBe(['p2a'])
        ->and(collect($row['legacy_phase_timeline'])->pluck('phase_code')->all())->toBe(['p1', 'p3'])
        ->and($row['legacy_phase_timeline'][0]['remarks'])->toBe('Legacy travel');
});

test('it identifies direct redeployment without return home phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $assignmentA = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-DIRECT-A',
            'position_id' => $rank->id,
            'started_at' => '2026-06-01 08:00:00',
        ]);

    $p4A = CrewAssignmentPhase::factory()->forAssignment($assignmentA)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-06-30 08:00:00',
    ]);
    $assignmentA->update(['current_phase_id' => $p4A->id]);

    $assignmentB = CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-DIRECT-B',
            'position_id' => $rank->id,
            'source' => 'vessel_transfer',
            'previous_assignment_id' => $assignmentA->id,
            'started_at' => '2026-06-30 09:00:00',
        ]);

    $p4B = CrewAssignmentPhase::factory()->forAssignment($assignmentB)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-06-30 09:00:00',
        'actual_end_at' => null,
    ]);
    $assignmentB->update(['current_phase_id' => $p4B->id]);

    $rowA = CrewMovementHistoryPresenter::toArray(
        $assignmentA->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
            'previousAssignment.phases',
            'previousAssignment.currentPhase',
            'previousAssignment.vessel',
            'previousAssignment.position',
            'previousAssignment.client',
            'nextAssignments.phases',
            'nextAssignments.currentPhase',
            'nextAssignments.vessel',
            'nextAssignments.position',
            'nextAssignments.client',
        ]),
    );

    expect($rowA['home_redeploy']['is_redeployed_directly'])->toBeTrue()
        ->and($rowA['home_redeploy']['outcome'])->toBe('redeployed')
        ->and($rowA['home_redeploy']['outcome_label'])->toBe('Redeployed')
        ->and($rowA['home_redeploy']['redeployed_at'])->toBe('2026-06-30 09:00:00')
        ->and($rowA['home_redeploy']['actual_return_home_at'])->toBeNull()
        ->and($rowA['home_redeploy']['from'])->toBeNull();
});

test('p5 then return home to p6 reports actual returned home', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-P5-RETURN-HOME',
            'position_id' => $rank->id,
            'started_at' => '2026-06-01 08:00:00',
            'closed_at' => '2026-07-05 18:00:00',
        ]);

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-06-30 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-30 08:00:00',
        'actual_end_at' => '2026-07-02 12:00:00',
    ]);
    $p6 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-07-02 12:00:00',
        'actual_end_at' => '2026-07-05 18:00:00',
    ]);
    $assignment->update(['current_phase_id' => $p6->id]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases']),
    );

    expect($row['home_redeploy']['outcome'])->toBe('returned_home')
        ->and($row['home_redeploy']['outcome_label'])->toBe('Returned Home')
        ->and($row['home_redeploy']['actual_return_home_at'])->toBe('2026-07-02 12:00:00')
        ->and($row['home_redeploy']['is_redeployed_directly'])->toBeFalse()
        ->and($row['home_redeploy']['redeployed_at'])->toBeNull();
});

test('direct p4 to p6 does not falsely claim actual returned home', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-P4-P6-DIRECT',
            'position_id' => $rank->id,
            'started_at' => '2026-06-01 08:00:00',
            'closed_at' => '2026-07-02 12:00:00',
        ]);

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-06-30 08:00:00',
    ]);
    $p6 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-06-30 08:00:00',
        'actual_end_at' => null,
    ]);
    $assignment->update(['current_phase_id' => $p6->id]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases']),
    );

    expect($row['home_redeploy']['outcome'])->toBe('home_redeploy')
        ->and($row['home_redeploy']['outcome_label'])->toBe('Home / Redeploy')
        ->and($row['home_redeploy']['actual_return_home_at'])->toBeNull()
        ->and($row['home_redeploy']['from'])->toBe('2026-06-30')
        ->and($row['home_redeploy']['is_redeployed_directly'])->toBeFalse();
});

test('p5 direct redeployment reports redeployed appropriately', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $source = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-P5-REDEPLOY-SOURCE',
            'position_id' => $rank->id,
            'started_at' => '2026-05-01 08:00:00',
            'closed_at' => '2026-06-15 10:00:00',
        ]);

    CrewAssignmentPhase::factory()->forAssignment($source)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-05-01 08:00:00',
        'actual_end_at' => '2026-06-10 08:00:00',
    ]);
    $p5 = CrewAssignmentPhase::factory()->forAssignment($source)->create([
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-10 08:00:00',
        'actual_end_at' => '2026-06-15 10:00:00',
    ]);
    $source->update(['current_phase_id' => $p5->id]);

    $next = CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-P5-REDEPLOY-DEST',
            'position_id' => $rank->id,
            'source' => 'redeployment',
            'previous_assignment_id' => $source->id,
            'started_at' => '2026-06-15 10:00:00',
        ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $source->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases', 'nextAssignments']),
    );

    expect($row['home_redeploy']['outcome'])->toBe('redeployed')
        ->and($row['home_redeploy']['outcome_label'])->toBe('Redeployed')
        ->and($row['home_redeploy']['is_redeployed_directly'])->toBeTrue()
        ->and($row['home_redeploy']['redeployed_at'])->toBe('2026-06-15 10:00:00')
        ->and($row['home_redeploy']['actual_return_home_at'])->toBeNull();
});

test('local assignment with foreign vessel and position does not expose foreign master data', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    ['company' => $foreignCompany] = makeCrewAssignmentFixtures();

    $foreignVessel = Vessel::factory()->create([
        'company_id' => $foreignCompany->id,
        'name' => 'Foreign Vessel X',
    ]);
    $foreignPosition = Position::query()->create([
        'company_id' => $foreignCompany->id,
        'title' => 'Foreign Chief Officer',
        'status' => 'active',
    ]);

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'company_id' => $company->id,
            'vessel_id' => $foreignVessel->id,
            'position_id' => $foreignPosition->id,
        ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases']),
    );

    expect($row['vessel'])->toBeNull()
        ->and($row['position'])->toBeNull();
});

test('malformed previous and next assignments from other company or hidden employee are sanitized', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $foreignCompany, 'employee' => $foreignEmployee, 'rank' => $foreignRank] = makeCrewAssignmentFixtures();

    $foreignPrevious = CrewAssignment::factory()
        ->forEmployee($foreignEmployee)
        ->create([
            'company_id' => $foreignCompany->id,
            'assignment_no' => 'CA-FOREIGN-PREV',
            'position_id' => $foreignRank->id,
        ]);

    $localAssignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'company_id' => $company->id,
            'assignment_no' => 'CA-LOCAL-TARGET',
            'position_id' => $rank->id,
            'source' => 'vessel_transfer',
            'previous_assignment_id' => $foreignPrevious->id,
        ]);

    $foreignNext = CrewAssignment::factory()
        ->forEmployee($foreignEmployee)
        ->create([
            'company_id' => $foreignCompany->id,
            'assignment_no' => 'CA-FOREIGN-NEXT',
            'previous_assignment_id' => $localAssignment->id,
        ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $localAssignment->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
            'previousAssignment',
            'nextAssignments',
        ]),
    );

    expect($row['linked_assignments']['previous'])->toBeNull()
        ->and($row['linked_assignments']['next'])->toBe([])
        ->and($row['linked_assignments']['relationship'])->toBeNull()
        ->and($row['linked_assignments']['relationship_label'])->toBeNull();

    // Now test mismatched employee in same company
    $otherEmployeeSameCompany = Employee::factory()->create(['company_id' => $company->id]);
    $mismatchedEmployeePrev = CrewAssignment::factory()
        ->forEmployee($otherEmployeeSameCompany)
        ->create([
            'company_id' => $company->id,
            'assignment_no' => 'CA-MISMATCHED-PREV',
            'position_id' => $rank->id,
        ]);

    $localAssignment->update(['previous_assignment_id' => $mismatchedEmployeePrev->id]);

    $mismatchedRow = CrewMovementHistoryPresenter::toArray(
        $localAssignment->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases',
            'previousAssignment',
            'nextAssignments',
        ]),
    );

    expect($mismatchedRow['linked_assignments']['previous'])->toBeNull();
});

test('foreign hotel and employee training do not leak across companies', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    ['company' => $foreignCompany, 'employee' => $foreignEmployee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();

    $trainingPhase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::Training,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-09-01 08:00:00',
        'actual_end_at' => '2026-09-02 17:00:00',
        'details' => ['provider' => 'Local Academy', 'course' => 'Safety'],
    ]);

    // Foreign employee training
    EmployeeTraining::factory()
        ->forEmployee($foreignEmployee)
        ->create([
            'company_id' => $foreignCompany->id,
            'source_crew_assignment_phase_id' => $trainingPhase->id,
        ]);

    // Foreign hotel
    $foreignHotel = Hotel::factory()->create([
        'company_id' => $foreignCompany->id,
        'name' => 'Foreign Secret Hotel',
    ]);
    $foreignRoomType = RoomType::factory()->create([
        'company_id' => $foreignCompany->id,
        'hotel_id' => $foreignHotel->id,
        'name' => 'Foreign Suite',
    ]);

    $stay = CrewAccommodationStay::factory()->make([
        'crew_assignment_id' => $assignment->id,
        'company_id' => $company->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'hotel_id' => $foreignHotel->id,
        'room_type_id' => $foreignRoomType->id,
        'check_in_date' => '2026-09-01',
        'check_out_date' => '2026-09-03',
    ]);
    $stay->saveQuietly();

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'position',
            'vessel',
            'client',
            'currentPhase',
            'phases.employeeTraining.course',
            'accommodationStays.hotel',
            'accommodationStays.roomType',
            'accommodationStays.startedFromPhase',
        ]),
    );

    // Foreign employee training must not be reported as linked
    expect($row['training']['history'][0]['employee_training_linked'])->toBeFalse()
        ->and($row['training']['history'][0]['employee_training'])->toBeNull()
        // Foreign hotel name and room type must be sanitized to null
        ->and($row['accommodation_stays'][0]['hotel_name'])->toBeNull()
        ->and($row['accommodation_stays'][0]['room_type_name'])->toBeNull();
});

test('case a: p4 to p6 with no linked next assignment reports home redeploy', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-CASE-A',
            'position_id' => $rank->id,
            'started_at' => '2026-06-01 08:00:00',
            'closed_at' => '2026-07-02 12:00:00',
        ]);

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-06-30 08:00:00',
    ]);
    $p6 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-30 08:00:00',
        'actual_end_at' => '2026-07-02 12:00:00',
    ]);
    $assignment->update(['current_phase_id' => $p6->id]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases', 'nextAssignments']),
    );

    expect($row['home_redeploy']['outcome'])->toBe('home_redeploy')
        ->and($row['home_redeploy']['outcome_label'])->toBe('Home / Redeploy')
        ->and($row['home_redeploy']['actual_return_home_at'])->toBeNull();
});

test('case b: p4 to p6 followed by linked redeployment reports redeployed', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-CASE-B',
            'position_id' => $rank->id,
            'started_at' => '2026-06-01 08:00:00',
            'closed_at' => '2026-07-02 12:00:00',
        ]);

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-06-30 08:00:00',
    ]);
    $p6 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-30 08:00:00',
        'actual_end_at' => '2026-07-02 12:00:00',
    ]);
    $assignment->update(['current_phase_id' => $p6->id]);

    CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-CASE-B-NEXT',
            'position_id' => $rank->id,
            'source' => 'redeployment',
            'previous_assignment_id' => $assignment->id,
            'started_at' => '2026-07-15 09:00:00',
        ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases', 'nextAssignments']),
    );

    expect($row['home_redeploy']['outcome'])->toBe('redeployed')
        ->and($row['home_redeploy']['outcome_label'])->toBe('Redeployed')
        ->and($row['home_redeploy']['actual_return_home_at'])->toBeNull()
        ->and($row['home_redeploy']['redeployed_at'])->toBe('2026-07-15 09:00:00');
});

test('case c: p5 to p6 followed by later redeployment still reports returned home', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-CASE-C',
            'position_id' => $rank->id,
            'started_at' => '2026-06-01 08:00:00',
            'closed_at' => '2026-07-10 18:00:00',
        ]);

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-06-30 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-30 08:00:00',
        'actual_end_at' => '2026-07-02 12:00:00',
    ]);
    $p6 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-07-02 12:00:00',
        'actual_end_at' => '2026-07-10 18:00:00',
    ]);
    $assignment->update(['current_phase_id' => $p6->id]);

    CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-CASE-C-NEXT',
            'position_id' => $rank->id,
            'source' => 'redeployment',
            'previous_assignment_id' => $assignment->id,
            'started_at' => '2026-08-01 09:00:00',
        ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh(['company', 'employee', 'position', 'vessel', 'client', 'currentPhase', 'phases', 'nextAssignments']),
    );

    expect($row['home_redeploy']['outcome'])->toBe('returned_home')
        ->and($row['home_redeploy']['outcome_label'])->toBe('Returned Home')
        ->and($row['home_redeploy']['actual_return_home_at'])->toBe('2026-07-02 12:00:00');
});
