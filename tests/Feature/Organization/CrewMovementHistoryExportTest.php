<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exports\CrewMovementHistoryExport;
use App\Models\Course;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeTraining;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Vessel;
use App\Support\Reports\CrewMovementHistoryFilters;
use App\Support\Reports\CrewMovementHistoryQuery;
use Maatwebsite\Excel\Facades\Excel;

function makeCrewMovementHistoryExportFixture(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'reports.crew_movement_history.view',
        'reports.crew_movement_history.export',
    ]);

    $fixtures['active'] = CrewAssignment::factory()
        ->forEmployee($fixtures['employee'])
        ->active()
        ->create(['assignment_no' => 'CA-EXPORT-ACTIVE']);
    $fixtures['completed'] = CrewAssignment::factory()
        ->forEmployee($fixtures['employee'])
        ->completed()
        ->create(['assignment_no' => 'CA-EXPORT-COMPLETED']);

    return $fixtures;
}

test('crew movement history export requires export permission', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, ['reports.crew_movement_history.view']);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.export'))
        ->assertForbidden();
});

test('crew movement history exports excel and csv with active filters', function () {
    Excel::fake();
    ['user' => $user] = makeCrewMovementHistoryExportFixture();

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.export', [
            'format' => 'xlsx',
            'status' => CrewAssignmentStatus::Completed->value,
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'crew-movement-history-'.now()->toDateString().'.xlsx',
        fn (CrewMovementHistoryExport $export): bool => $export->query()->count() === 1
            && $export->query()->first()?->assignment_no === 'CA-EXPORT-COMPLETED',
    );

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.export', [
            'format' => 'csv',
            'search' => 'CA-EXPORT-ACTIVE',
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'crew-movement-history-'.now()->toDateString().'.csv',
        fn (CrewMovementHistoryExport $export): bool => $export->query()->count() === 1
            && $export->query()->first()?->assignment_no === 'CA-EXPORT-ACTIVE',
    );
});

test('export has clear headings and one mapped row per crew assignment', function () {
    ['company' => $company, 'active' => $active] = makeCrewMovementHistoryExportFixture();
    $query = new CrewMovementHistoryQuery(
        $company->id,
        new CrewMovementHistoryFilters,
        $company->timezone,
    );
    $export = CrewMovementHistoryExport::forQuery($query->exportQuery());
    $assignment = $query->exportQuery()->whereKey($active->id)->firstOrFail();

    expect($export->headings())
        ->toContain(
            'Assignment No',
            'Planned Arrival',
            'Planned Sign-Off',
            'Planned Sign-Off Source',
            'Tour of Duty Days',
            'Actual Arrival Date/Time',
            'Actual Join Date/Time',
            'Accommodation History',
            'Previous Assignment',
            'Sign-On Standby Days',
            'Total Movement Calendar Days',
            'Join Standby Periods',
            'Training Details',
            'Needs Attention',
            'Pending Correction',
        )
        ->not->toContain(
            'Planned Travel In',
            'P1 From',
            'Ready From',
            'Legacy Travel In Periods',
        )
        ->and($export->map($assignment)[0])->toBe('CA-EXPORT-ACTIVE')
        ->and($export->query()->count())->toBe(2);
});

test('export adds legacy columns only when the filtered result set contains legacy phases', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewMovementHistoryExportFixture();

    $legacyAssignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create(['assignment_no' => 'CA-EXPORT-LEGACY']);

    CrewAssignmentPhase::factory()->forAssignment($legacyAssignment)->create([
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'planned_start_at' => '2026-01-02',
        'actual_start_at' => '2026-01-03',
        'actual_end_at' => '2026-01-04',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($legacyAssignment)->create([
        'phase_code' => CrewPhaseCode::ReadyToJoin,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-01-04',
        'actual_end_at' => '2026-01-10',
    ]);

    $query = new CrewMovementHistoryQuery(
        $company->id,
        new CrewMovementHistoryFilters(search: 'CA-EXPORT-LEGACY'),
        $company->timezone,
    );
    $export = CrewMovementHistoryExport::forQuery($query->exportQuery());
    $assignment = $query->exportQuery()->whereKey($legacyAssignment->id)->firstOrFail();

    expect($export->headings())->toContain(
        'Legacy Planned Travel In',
        'Legacy Travel In Periods',
        'Legacy Ready To Join Periods',
    );

    $mapped = $export->map($assignment);
    expect($mapped[0])->toBe('CA-EXPORT-LEGACY')
        ->and($mapped)->toContain('03 Jan 2026', '04 Jan 2026');
});

test('export maps rich training phase timeline accommodation and redeployment values', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementHistoryExportFixture();

    $sourceVessel = Vessel::factory()->create(['company_id' => $company->id, 'name' => 'Vessel A']);
    $destinationVessel = Vessel::factory()->create(['company_id' => $company->id, 'name' => 'Vessel B']);
    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Harbor Inn']);
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Standard',
    ]);
    $course = Course::factory()->create(['name' => 'BOSIET']);

    $source = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-EXPORT-SOURCE',
            'rank_id' => $rank->id,
            'vessel_id' => $sourceVessel->id,
        ]);

    $destination = CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-EXPORT-RICH',
            'rank_id' => $rank->id,
            'vessel_id' => $destinationVessel->id,
            'source' => 'redeployment',
            'previous_assignment_id' => $source->id,
            'tour_of_duty_days' => 60,
            'planned_signoff_at' => '2026-11-15',
            'started_at' => '2026-09-10 08:00:00',
        ]);

    CrewAssignmentPhase::factory()->forAssignment($destination)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'planned_start_at' => '2026-09-10 08:00:00',
        'planned_end_at' => '2026-09-12 08:00:00',
        'actual_start_at' => '2026-09-10 09:15:00',
        'actual_end_at' => '2026-09-12 07:30:00',
        'remarks' => 'Client requested additional standby',
    ]);

    $training = CrewAssignmentPhase::factory()->forAssignment($destination)->create([
        'phase_code' => CrewPhaseCode::Training,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'planned_start_at' => '2026-09-10 09:00:00',
        'planned_end_at' => '2026-09-12 17:00:00',
        'actual_start_at' => '2026-09-10 09:30:00',
        'actual_end_at' => '2026-09-12 16:00:00',
        'details' => ['provider' => 'ABC Training Centre', 'course' => 'BOSIET'],
        'remarks' => 'Refresher required by client',
    ]);

    $onVessel = CrewAssignmentPhase::factory()->forAssignment($destination)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-09-13 08:00:00',
        'actual_end_at' => null,
    ]);
    $destination->update(['current_phase_id' => $onVessel->id]);

    EmployeeTraining::factory()
        ->forEmployee($employee)
        ->create([
            'course_id' => $course->id,
            'source_crew_assignment_phase_id' => $training->id,
        ]);

    CrewAccommodationStay::factory()->create([
        'crew_assignment_id' => $destination->id,
        'company_id' => $company->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'check_in_date' => '2026-09-10',
        'check_out_date' => '2026-09-12',
    ]);

    $query = new CrewMovementHistoryQuery(
        $company->id,
        new CrewMovementHistoryFilters(search: 'CA-EXPORT-RICH'),
        $company->timezone,
    );
    $export = CrewMovementHistoryExport::forQuery($query->exportQuery());
    $assignment = $query->exportQuery()->whereKey($destination->id)->firstOrFail();

    $headings = $export->headings();
    $mapped = $export->map($assignment);
    $byHeading = array_combine($headings, $mapped);

    expect($byHeading['Assignment No'])->toBe('CA-EXPORT-RICH')
        ->and($byHeading['Training History'])->toContain('Training #1')
        ->and($byHeading['Training History'])->toContain('Provider: ABC Training Centre')
        ->and($byHeading['Training History'])->toContain('Course: BOSIET')
        ->and($byHeading['Training History'])->toContain('Remarks: Refresher required by client')
        ->and($byHeading['Training History'])->toContain('Employee Training: Linked')
        ->and($byHeading['Training History'])->toContain('BOSIET')
        ->and($byHeading['Phase Timeline'])->toContain('P2A #1 [seq 1] Completed')
        ->and($byHeading['Phase Timeline'])->toContain('Planned:')
        ->and($byHeading['Phase Timeline'])->toContain('Remarks: Client requested additional standby')
        ->and($byHeading['Phase Timeline'])->toContain('P2B #1 [seq 2] Completed')
        ->and($byHeading['Starting Checkpoint'])->toBe('P2A · Join Standby')
        ->and($byHeading['Previous Assignment'])->toBe('CA-EXPORT-SOURCE')
        ->and($byHeading['Movement Relationship'])->toBe('Redeployment')
        ->and($byHeading['Accommodation History'])->toContain('Harbor Inn')
        ->and($byHeading['Tour of Duty Days'])->toBe(60);
});
