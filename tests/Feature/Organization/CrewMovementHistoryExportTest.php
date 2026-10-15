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
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Vessel;
use App\Support\Reports\CrewMovementHistoryFilters;
use App\Support\Reports\CrewMovementHistoryPresenter;
use App\Support\Reports\CrewMovementHistoryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

    // Summary sheet headings (operator-friendly)
    expect($export->headings())
        ->toContain(
            'Employee No',
            'Employee Name',
            'Rank',
            'Vessel',
            'Client',
            'Arrival Date',
            'Pre-Joining Standby (Days)',
            'Join Vessel Date',
            'Sign-Off / Disembarkation Date',
            'Demobilisation Standby (Days)',
            'Return Home Date',
            'Total Standby',
            'Vessel Service Days',
            'Assignment Status',
            'Assignment No',
            'Assignment Source',
            'Remarks',
        )
        ->not->toContain(
            'Planned Travel In',
            'P1 From',
            'Legacy Travel In Periods',
        );

    // Multi-sheet XLSX verification
    $sheets = $export->sheets();
    expect($sheets)->toHaveCount(2)
        ->and($sheets[0]->title())->toBe('CREW HISTORY')
        ->and($sheets[1]->title())->toBe('MOVEMENT DETAILS');

    // Rich movement details sheet
    $detailsSheet = $sheets[1];
    expect($detailsSheet->headings())
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
            'Pre-Joining Standby Service Days',
            'Demobilisation Standby Service Days',
            'Total Standby Service Days',
            'Sign-On Standby Days',
            'Total Movement Calendar Days',
            'Pre-Joining Standby Periods',
            'Training Details',
            'Needs Attention',
            'Pending Correction',
        );

    $summaryMapped = $export->map($assignment);
    $summaryByHeading = array_combine($export->headings(), $summaryMapped);

    expect($summaryByHeading['Assignment No'])->toBe('CA-EXPORT-ACTIVE')
        ->and($summaryByHeading['Assignment Status'])->toBe('Active')
        ->and($export->query()->count())->toBe(2);

    // CSV format produces only the CREW HISTORY summary sheet
    $csvExport = CrewMovementHistoryExport::forQuery($query->exportQuery(), 'csv');
    expect($csvExport->sheets())->toHaveCount(1)
        ->and($csvExport->sheets()[0]->title())->toBe('CREW HISTORY');
});

test('export no longer adds legacy travel in columns for modern phase rows', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewMovementHistoryExportFixture();

    $legacyAssignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create(['assignment_no' => 'CA-EXPORT-LEGACY']);

    CrewAssignmentPhase::factory()->forAssignment($legacyAssignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'planned_start_at' => '2026-01-02',
        'actual_start_at' => '2026-01-03',
        'actual_end_at' => '2026-01-04',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($legacyAssignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
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

    $detailsSheet = $export->sheets()[1];
    expect($detailsSheet->headings())->not->toContain(
        'Legacy Planned Travel In',
        'Legacy Travel In Periods',
        'Legacy Ready To Join Periods',
    );

    $mapped = $detailsSheet->map($assignment);
    expect($mapped[0])->toBe('CA-EXPORT-LEGACY')
        ->and(collect($mapped)->filter(fn ($value) => is_string($value) && str_contains($value, 'Jan 2026'))->isNotEmpty())->toBeTrue();
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
            'position_id' => $rank->id,
            'vessel_id' => $sourceVessel->id,
        ]);

    $destination = CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-EXPORT-RICH',
            'position_id' => $rank->id,
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

    // Verify detailed sheet
    $detailsSheet = $export->sheets()[1];
    $headings = $detailsSheet->headings();
    $mapped = $detailsSheet->map($assignment);
    $byHeading = array_combine($headings, $mapped);

    expect($byHeading['Assignment No'])->toBe('CA-EXPORT-RICH')
        ->and($byHeading['Training History'])->toContain('Training #1')
        ->and($byHeading['Training History'])->toContain('Provider: ABC Training Centre')
        ->and($byHeading['Training History'])->toContain('Course: BOSIET')
        ->and($byHeading['Training History'])->toContain('Remarks: Refresher required by client')
        ->and($byHeading['Training History'])->toContain('Employee Training: Linked')
        ->and($byHeading['Training History'])->toContain('BOSIET')
        ->and($byHeading['Phase Timeline'])->toContain('P1A #1 [seq 1] Completed')
        ->and($byHeading['Phase Timeline'])->toContain('Planned:')
        ->and($byHeading['Phase Timeline'])->toContain('Remarks: Client requested additional standby')
        ->and($byHeading['Phase Timeline'])->toContain('P1B #1 [seq 2] Completed')
        ->and($byHeading['Starting Checkpoint'])->toBe('P1A · Pre-Joining Standby')
        ->and($byHeading['Previous Assignment'])->toBe('CA-EXPORT-SOURCE')
        ->and($byHeading['Movement Relationship'])->toBe('Redeployment')
        ->and($byHeading['Accommodation History'])->toContain('Harbor Inn')
        ->and($byHeading['Tour of Duty Days'])->toBe(60);

    // Verify summary sheet
    $summarySheet = $export->sheets()[0];
    $summaryHeadings = $summarySheet->headings();
    $summaryMapped = $summarySheet->map($assignment);
    $summaryByHeading = array_combine($summaryHeadings, $summaryMapped);

    expect($summaryByHeading['Assignment No'])->toBe('CA-EXPORT-RICH')
        ->and($summaryByHeading['Assignment Status'])->toBe('Active')
        ->and($summaryByHeading['Sign-Off / Disembarkation Date'])->toBe('Ongoing')
        ->and($summaryByHeading['Return Home Date'])->toBe('—');

    // Verify source assignment has 'Redeployed' as Return Home Date
    $sourceQuery = new CrewMovementHistoryQuery(
        $company->id,
        new CrewMovementHistoryFilters(search: 'CA-EXPORT-SOURCE'),
        $company->timezone,
    );
    $sourceAssignment = $sourceQuery->exportQuery()->whereKey($source->id)->firstOrFail();
    $sourceMapped = $export->sheets()[0]->map($sourceAssignment);
    $sourceByHeading = array_combine($summaryHeadings, $sourceMapped);
    expect($sourceByHeading['Return Home Date'])->toContain('Redeployed');
});

test('export accurately maps returned home vs home redeploy vs redeployed semantics across sheets', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewMovementHistoryExportFixture();

    // 1. Returned Home: completed P5 demobilisation standby, then P6 home/redeploy
    $returnedHomeAssignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create(['assignment_no' => 'CA-EXPORT-RET-HOME']);

    CrewAssignmentPhase::factory()->forAssignment($returnedHomeAssignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-09-01 08:00:00',
        'actual_end_at' => '2026-09-30 18:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($returnedHomeAssignment)->create([
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 09:00:00',
        'actual_end_at' => '2026-10-02 12:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($returnedHomeAssignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-02 14:00:00',
        'actual_end_at' => '2026-10-10 18:00:00',
    ]);

    // 2. Direct P4 -> P6: on-vessel directly to home/redeploy without demob standby
    $directP6Assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create(['assignment_no' => 'CA-EXPORT-DIR-P6']);

    CrewAssignmentPhase::factory()->forAssignment($directP6Assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-09-01 08:00:00',
        'actual_end_at' => '2026-09-30 18:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($directP6Assignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 09:00:00',
        'actual_end_at' => '2026-10-15 18:00:00',
    ]);

    $query = new CrewMovementHistoryQuery(
        $company->id,
        new CrewMovementHistoryFilters,
        $company->timezone,
    );
    $export = CrewMovementHistoryExport::forQuery($query->exportQuery());
    $sheets = $export->sheets();
    $summarySheet = $sheets[0];
    $detailsSheet = $sheets[1];

    $summaryHeadings = $summarySheet->headings();
    $detailsHeadings = $detailsSheet->headings();

    // Verify 1: Returned Home
    $retHomeModel = $query->exportQuery()->whereKey($returnedHomeAssignment->id)->firstOrFail();
    $summaryRet = array_combine($summaryHeadings, $summarySheet->map($retHomeModel));
    $detailsRet = array_combine($detailsHeadings, $detailsSheet->map($retHomeModel));

    expect($summaryRet['Return Home Date'])->toBe('02 Oct 2026')
        ->and($detailsRet['Actual Return Home Date/Time'])->toContain('02 Oct 2026');

    // Verify 2: Direct P4 -> P6
    $dirP6Model = $query->exportQuery()->whereKey($directP6Assignment->id)->firstOrFail();
    $summaryDir = array_combine($summaryHeadings, $summarySheet->map($dirP6Model));
    $detailsDir = array_combine($detailsHeadings, $detailsSheet->map($dirP6Model));

    expect($summaryDir['Return Home Date'])->toBe('Home / Redeploy: 01 Oct 2026')
        ->and($detailsDir['Actual Return Home Date/Time'])->toBe('Not recorded');
});

test('crew history export agrees with web presenter across p4 to p6, p4 to p6 to redeploy, and p5 to p6 to later redeploy in xlsx and csv', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    // 1. P4 -> P6 (no linked next assignment)
    $p4p6Assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-EXP-P4-P6',
            'position_id' => $rank->id,
            'started_at' => '2026-06-01 08:00:00',
            'closed_at' => '2026-07-02 12:00:00',
        ]);
    CrewAssignmentPhase::factory()->forAssignment($p4p6Assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-06-30 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($p4p6Assignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-30 08:00:00',
        'actual_end_at' => '2026-07-02 12:00:00',
    ]);

    // 2. P4 -> P6 -> Redeploy
    $p4p6RedeployAssignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-EXP-P4-P6-REDEPLOY',
            'position_id' => $rank->id,
            'started_at' => '2026-07-01 08:00:00',
            'closed_at' => '2026-07-20 12:00:00',
        ]);
    CrewAssignmentPhase::factory()->forAssignment($p4p6RedeployAssignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-07-01 08:00:00',
        'actual_end_at' => '2026-07-15 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($p4p6RedeployAssignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-07-15 08:00:00',
        'actual_end_at' => '2026-07-20 12:00:00',
    ]);
    CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-EXP-P4-P6-REDEPLOY-NEXT',
            'position_id' => $rank->id,
            'source' => 'redeployment',
            'previous_assignment_id' => $p4p6RedeployAssignment->id,
            'started_at' => '2026-07-25 09:00:00',
        ]);

    // 3. P5 -> P6 -> later Redeploy
    $p5p6RedeployAssignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'assignment_no' => 'CA-EXP-P5-P6-LATER-REDEPLOY',
            'position_id' => $rank->id,
            'started_at' => '2026-08-01 08:00:00',
            'closed_at' => '2026-08-25 18:00:00',
        ]);
    CrewAssignmentPhase::factory()->forAssignment($p5p6RedeployAssignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-08-01 08:00:00',
        'actual_end_at' => '2026-08-20 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($p5p6RedeployAssignment)->create([
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-08-20 08:00:00',
        'actual_end_at' => '2026-08-22 12:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($p5p6RedeployAssignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-08-22 12:00:00',
        'actual_end_at' => '2026-08-25 18:00:00',
    ]);
    CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-EXP-P5-P6-REDEPLOY-NEXT',
            'position_id' => $rank->id,
            'source' => 'redeployment',
            'previous_assignment_id' => $p5p6RedeployAssignment->id,
            'started_at' => '2026-09-05 09:00:00',
        ]);

    $query = new CrewMovementHistoryQuery(
        $company->id,
        new CrewMovementHistoryFilters,
        $company->timezone,
    );
    $xlsxExport = CrewMovementHistoryExport::forQuery($query->exportQuery(), 'xlsx');
    $csvExport = CrewMovementHistoryExport::forQuery($query->exportQuery(), 'csv');

    $summarySheet = $xlsxExport->sheets()[0];
    $headings = $summarySheet->headings();

    // 1. Verify P4 -> P6
    $model1 = $query->exportQuery()->whereKey($p4p6Assignment->id)->firstOrFail();
    $presenter1 = CrewMovementHistoryPresenter::toArray($model1);
    $xlsxRow1 = array_combine($headings, $summarySheet->map($model1));
    $csvRow1 = array_combine($headings, $csvExport->map($model1));

    expect($presenter1['home_redeploy']['outcome'])->toBe('home_redeploy')
        ->and($presenter1['home_redeploy']['from'])->toBe('2026-06-30')
        ->and($xlsxRow1['Return Home Date'])->toBe('Home / Redeploy: 30 Jun 2026')
        ->and($csvRow1['Return Home Date'])->toBe('Home / Redeploy: 30 Jun 2026');

    // 2. Verify P4 -> P6 -> Redeploy
    $model2 = $query->exportQuery()->whereKey($p4p6RedeployAssignment->id)->firstOrFail();
    $presenter2 = CrewMovementHistoryPresenter::toArray($model2);
    $xlsxRow2 = array_combine($headings, $summarySheet->map($model2));
    $csvRow2 = array_combine($headings, $csvExport->map($model2));

    expect($presenter2['home_redeploy']['outcome'])->toBe('redeployed')
        ->and($presenter2['home_redeploy']['redeployed_at'])->toBe('2026-07-25 09:00:00')
        ->and($xlsxRow2['Return Home Date'])->toBe('Redeployed: 25 Jul 2026')
        ->and($csvRow2['Return Home Date'])->toBe('Redeployed: 25 Jul 2026');

    // 3. Verify P5 -> P6 -> later Redeploy
    $model3 = $query->exportQuery()->whereKey($p5p6RedeployAssignment->id)->firstOrFail();
    $presenter3 = CrewMovementHistoryPresenter::toArray($model3);
    $xlsxRow3 = array_combine($headings, $summarySheet->map($model3));
    $csvRow3 = array_combine($headings, $csvExport->map($model3));

    expect($presenter3['home_redeploy']['outcome'])->toBe('returned_home')
        ->and($presenter3['home_redeploy']['actual_return_home_at'])->toBe('2026-08-22 12:00:00')
        ->and($xlsxRow3['Return Home Date'])->toBe('22 Aug 2026')
        ->and($csvRow3['Return Home Date'])->toBe('22 Aug 2026');
});

test('web summary and excel csv exports share allocated vessel and standby service days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewMovementHistoryExportFixture();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->completed()->create([
        'assignment_no' => 'CA-EXPORT-DAYS',
    ]);

    foreach ([
        [CrewPhaseCode::JoinStandby, 1, '2026-10-02 08:00:00', '2026-10-04 08:00:00'],
        [CrewPhaseCode::OnVessel, 2, '2026-10-04 08:00:00', '2026-10-07 08:00:00'],
        [CrewPhaseCode::DemobStandby, 3, '2026-10-07 08:00:00', '2026-10-13 08:00:00'],
    ] as [$code, $sequence, $start, $end]) {
        CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
            'phase_code' => $code,
            'sequence' => $sequence,
            'status' => CrewPhaseStatus::Completed,
            'actual_start_at' => $start,
            'actual_end_at' => $end,
        ]);
    }

    $query = new CrewMovementHistoryQuery($company->id, new CrewMovementHistoryFilters, $company->timezone);
    $model = $query->exportQuery()->whereKey($assignment->id)->firstOrFail();
    $presenter = CrewMovementHistoryPresenter::toArray($model);
    $summary = CrewMovementHistoryExport::forQuery($query->exportQuery());
    $csv = CrewMovementHistoryExport::forQuery($query->exportQuery(), 'csv');
    $summaryRow = array_combine($summary->headings(), $summary->map($model));
    $csvRow = array_combine($csv->headings(), $csv->map($model));
    $details = $summary->sheets()[1];
    $detailRow = array_combine($details->headings(), $details->map($model));

    expect($presenter['vessel_service_days'])->toBe(4)
        ->and($presenter['pre_joining_standby_days'])->toBe(2)
        ->and($presenter['demobilisation_standby_days'])->toBe(6)
        ->and($presenter['standby_service_days'])->toBe(8)
        ->and($presenter['payroll_days']['onsite']['total_days'])->toBe(4)
        ->and($presenter['payroll_days']['sign_on_standby']['total_days'])->toBe(2)
        ->and($presenter['payroll_days']['sign_off_standby']['total_days'])->toBe(6)
        ->and($summaryRow['Vessel Service Days'])->toBe('4 days')
        ->and($summaryRow['Pre-Joining Standby (Days)'])->toBe('2 days')
        ->and($summaryRow['Demobilisation Standby (Days)'])->toBe('6 days')
        ->and($summaryRow['Total Standby'])->toBe('8 days')
        ->and($csvRow['Vessel Service Days'])->toBe('4 days')
        ->and($csvRow['Pre-Joining Standby (Days)'])->toBe('2 days')
        ->and($csvRow['Demobilisation Standby (Days)'])->toBe('6 days')
        ->and($csvRow['Total Standby'])->toBe('8 days')
        ->and($detailRow['Vessel Service Days'])->toBe('4 days')
        ->and($detailRow['Pre-Joining Standby Service Days'])->toBe('2 days')
        ->and($detailRow['Demobilisation Standby Service Days'])->toBe('6 days')
        ->and($detailRow['Total Standby Service Days'])->toBe('8 days')
        ->and($detailRow['On Vessel Days'])->toBe(4)
        ->and($detailRow['Pre-Joining Standby Days'])->toBe(2)
        ->and($detailRow['Demobilisation Standby Days'])->toBe(6)
        ->and($detailRow['Sign-On Standby Days'])->toBe(2)
        ->and($detailRow['Sign-Off Standby Days'])->toBe(6)
        ->and($presenter['join_standby']['total_days'])->toBe(2)
        ->and($presenter['join_standby']['calendar_span_days'])->toBe(3)
        ->and($presenter['demob_standby']['total_days'])->toBe(6)
        ->and($presenter['demob_standby']['calendar_span_days'])->toBe(7);

    CarbonImmutable::setTestNow();
});

test('linked same-day vessel transfer is one onsite day in the report query and exports', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $source = CrewAssignment::factory()->forEmployee($employee)->completed()->create([
        'assignment_no' => 'CA-TRANSFER-A',
        'source' => 'manual',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($source)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-10 08:00:00',
    ]);

    $destination = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'assignment_no' => 'CA-TRANSFER-B',
        'source' => 'vessel_transfer',
        'previous_assignment_id' => $source->id,
    ]);
    CrewAssignmentPhase::factory()->forAssignment($destination)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-10-10 08:00:00',
        'actual_end_at' => null,
    ]);

    $query = new CrewMovementHistoryQuery($company->id, new CrewMovementHistoryFilters, 'Asia/Dubai');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $page = $query->paginate(25);
    $phaseQueries = collect(DB::getQueryLog())
        ->filter(fn (array $logged): bool => str_contains($logged['query'], 'crew_assignment_phases'))
        ->count();
    DB::disableQueryLog();

    $rows = collect($page->items())->keyBy('id');
    $sourceRow = $rows->get($source->id);
    $destinationRow = $rows->get($destination->id);

    expect($sourceRow)->not->toBeNull()
        ->and($destinationRow)->not->toBeNull()
        ->and($sourceRow['on_vessel']['total_days'])->toBe(10)
        ->and($sourceRow['on_vessel']['calendar_span_days'])->toBe(10)
        ->and($destinationRow['on_vessel']['total_days'])->toBe(10)
        ->and($destinationRow['on_vessel']['calendar_span_days'])->toBe(11)
        ->and($sourceRow['vessel_service_days'] + $destinationRow['vessel_service_days'])->toBe(20)
        ->and($sourceRow['payroll_days']['onsite']['total_days'] + $destinationRow['payroll_days']['onsite']['total_days'])->toBe(20)
        ->and($sourceRow['payroll_days']['onsite']['periods'][0])->toMatchArray(['from' => '2026-10-01', 'to' => '2026-10-10'])
        ->and($destinationRow['payroll_days']['onsite']['periods'][0]['from'])->toBe('2026-10-11')
        ->and($phaseQueries)->toBeLessThanOrEqual(6);

    $summary = CrewMovementHistoryExport::forQuery($query->exportQuery());
    $csv = CrewMovementHistoryExport::forQuery($query->exportQuery(), 'csv');
    $details = $summary->sheets()[1];

    foreach ([$source, $destination] as $assignment) {
        $model = $query->exportQuery()->whereKey($assignment->id)->firstOrFail();
        $web = $rows->get($assignment->id);
        $summaryRow = array_combine($summary->headings(), $summary->map($model));
        $csvRow = array_combine($csv->headings(), $csv->map($model));
        $detailRow = array_combine($details->headings(), $details->map($model));

        expect($summaryRow['Vessel Service Days'])->toBe($web['vessel_service_days'].' days')
            ->and($csvRow['Vessel Service Days'])->toBe($web['vessel_service_days'].' days')
            ->and($detailRow['On Vessel Days'])->toBe($web['on_vessel']['total_days'])
            ->and($detailRow['Vessel Service Days'])->toBe($web['vessel_service_days'].' days');
    }

    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $otherPage = (new CrewMovementHistoryQuery($otherCompany->id, new CrewMovementHistoryFilters, 'Asia/Dubai'))
        ->paginate(25);

    expect(collect($otherPage->items())->pluck('id'))
        ->not->toContain($source->id, $destination->id);

    CarbonImmutable::setTestNow();
});

test('linked same-day redeployment does not duplicate the handoff in report payable days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $source = CrewAssignment::factory()->forEmployee($employee)->completed()->create([
        'assignment_no' => 'CA-REDEPLOY-A',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($source)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-10 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($source)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-10 08:00:00',
        'actual_end_at' => '2026-10-10 18:00:00',
    ]);

    $destination = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'assignment_no' => 'CA-REDEPLOY-B',
        'source' => 'redeployment',
        'previous_assignment_id' => $source->id,
    ]);
    CrewAssignmentPhase::factory()->forAssignment($destination)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-10-10 08:00:00',
        'actual_end_at' => null,
    ]);

    $page = (new CrewMovementHistoryQuery($company->id, new CrewMovementHistoryFilters, 'Asia/Dubai'))->paginate(25);
    $rows = collect($page->items())->keyBy('id');
    $sourceRow = $rows->get($source->id);
    $destinationRow = $rows->get($destination->id);

    expect($sourceRow['on_vessel']['total_days'])->toBe(10)
        ->and($sourceRow['home_redeploy']['total_days'])->toBe(1)
        ->and($sourceRow['home_redeploy']['calendar_span_days'])->toBe(1)
        ->and($sourceRow['payroll_days']['onsite']['total_days'])->toBe(10)
        ->and($destinationRow['on_vessel']['total_days'])->toBe(10)
        ->and($destinationRow['on_vessel']['calendar_span_days'])->toBe(11)
        ->and($sourceRow['payroll_days']['onsite']['total_days'] + $destinationRow['payroll_days']['onsite']['total_days'])->toBe(20)
        ->and($sourceRow['standby_service_days'])->toBe(0)
        ->and($destinationRow['standby_service_days'])->toBe(0);

    CarbonImmutable::setTestNow();
});

test('movement history export batches linked phase lookups for multiple employees', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['company' => $company, 'employee' => $firstEmployee] = makeCrewAssignmentFixtures();
    $secondEmployee = Employee::factory()->forCompany($company)->create();

    $ids = [];

    foreach ([$firstEmployee, $secondEmployee] as $index => $employee) {
        $source = CrewAssignment::factory()->forEmployee($employee)->completed()->create([
            'assignment_no' => 'CA-BATCH-'.$index.'-A',
        ]);
        CrewAssignmentPhase::factory()->forAssignment($source)->create([
            'phase_code' => CrewPhaseCode::OnVessel,
            'sequence' => 1,
            'status' => CrewPhaseStatus::Completed,
            'actual_start_at' => '2026-10-01 08:00:00',
            'actual_end_at' => '2026-10-10 08:00:00',
        ]);

        $destination = CrewAssignment::factory()->forEmployee($employee)->active()->create([
            'assignment_no' => 'CA-BATCH-'.$index.'-B',
            'source' => 'vessel_transfer',
            'previous_assignment_id' => $source->id,
        ]);
        CrewAssignmentPhase::factory()->forAssignment($destination)->create([
            'phase_code' => CrewPhaseCode::OnVessel,
            'sequence' => 1,
            'status' => CrewPhaseStatus::Active,
            'actual_start_at' => '2026-10-10 08:00:00',
            'actual_end_at' => null,
        ]);

        $ids[] = $source->id;
        $ids[] = $destination->id;
    }

    $query = new CrewMovementHistoryQuery($company->id, new CrewMovementHistoryFilters, 'Asia/Dubai');
    $models = $query->exportQuery()->whereIn('crew_assignments.id', $ids)->get();
    $sheet = CrewMovementHistoryExport::forQuery($query->exportQuery())->sheets()[0];
    $prepared = $sheet->prepareRows($models);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $mapped = [];

    foreach ($prepared as $model) {
        $mapped[$model->id] = $sheet->map($model);
    }

    $phaseQueries = collect(DB::getQueryLog())
        ->filter(fn (array $logged): bool => str_contains($logged['query'], 'crew_assignment_phases'))
        ->count();
    DB::disableQueryLog();

    $headings = $sheet->headings();
    $vesselColumn = array_search('Vessel Service Days', $headings, true);

    expect($models)->toHaveCount(4)
        ->and($phaseQueries)->toBe(0)
        ->and($mapped[$ids[0]][$vesselColumn])->toBe('10 days')
        ->and($mapped[$ids[1]][$vesselColumn])->toBe('10 days')
        ->and($mapped[$ids[2]][$vesselColumn])->toBe('10 days')
        ->and($mapped[$ids[3]][$vesselColumn])->toBe('10 days');

    CarbonImmutable::setTestNow();
});
