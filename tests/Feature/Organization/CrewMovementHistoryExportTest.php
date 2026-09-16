<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exports\CrewMovementHistoryExport;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
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
            'Actual Arrival',
            'Join Standby Periods',
            'Training Details',
            'Needs Attention',
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
