<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\Reports\CrewMovementHistoryFilters;
use App\Support\Reports\CrewMovementHistoryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function authorizeCrewMovementHistoryReport(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'reports.crew_movement_history.view',
        'reports.crew_movement_history.export',
    ]);

    return $fixtures;
}

test('crew movement history requires authentication and view permission', function () {
    $this->get(route('organization.reports.crew-movement-history.index'))
        ->assertRedirect(route('login'));

    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index'))
        ->assertForbidden();
});

test('report is company scoped and keeps one row per assignment for the same employee', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = authorizeCrewMovementHistoryReport();
    $first = CrewAssignment::factory()->forEmployee($employee)->create(['assignment_no' => 'CA-HISTORY-001']);
    $second = CrewAssignment::factory()->forEmployee($employee)->create(['assignment_no' => 'CA-HISTORY-002']);
    ['company' => $otherCompany, 'employee' => $otherEmployee] = makeCrewAssignmentFixtures();
    CrewAssignment::factory()->forEmployee($otherEmployee)->create([
        'company_id' => $otherCompany->id,
        'assignment_no' => 'CA-FOREIGN-001',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/reports/crew-movement-history/index')
            ->has('assignments', 2)
            ->where('assignments.0.employee.id', $employee->id)
            ->where('assignments.1.employee.id', $employee->id)
            ->where('summary.total', 2)
            ->where('can.export', true)
            ->where('assignments', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$first->id, $second->id])->sort()->values()->all()));
});

test('report exposes repeated phases and authoritative p4 dates in one row', function () {
    CarbonImmutable::setTestNow('2026-07-25 12:00:00');
    ['user' => $user, 'employee' => $employee] = authorizeCrewMovementHistoryReport();
    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->active()
        ->create([
            'assignment_no' => 'CA-REPEATED-001',
            'planned_signoff_at' => '2026-09-01',
        ]);

    foreach ([
        [CrewPhaseCode::JoinStandby, 1, '2026-07-15', '2026-07-17', null],
        [CrewPhaseCode::Training, 2, '2026-07-17', '2026-07-19', ['provider' => 'ABC', 'course' => 'BOSIET']],
        [CrewPhaseCode::JoinStandby, 3, '2026-07-19', '2026-07-22', null],
        [CrewPhaseCode::Training, 4, '2026-07-22', '2026-07-24', ['provider' => 'XYZ', 'course' => 'Refresher']],
    ] as [$code, $sequence, $start, $end, $details]) {
        CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
            'phase_code' => $code,
            'sequence' => $sequence,
            'status' => CrewPhaseStatus::Completed,
            'actual_start_at' => $start,
            'actual_end_at' => $end,
            'details' => $details,
        ]);
    }

    $p4 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 5,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-07-24',
        'actual_end_at' => null,
    ]);
    $assignment->update(['current_phase_id' => $p4->id]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index', ['search' => 'CA-REPEATED-001']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->has('assignments.0.join_standby.periods', 2)
            ->has('assignments.0.training.periods', 2)
            ->where('assignments.0.training.details.0', 'ABC — BOSIET')
            ->where('assignments.0.training.details.1', 'XYZ — Refresher')
            ->where('assignments.0.on_vessel.actual_join', '2026-07-24')
            ->where('assignments.0.on_vessel.actual_disembarkation', null)
            ->where('assignments.0.planned_signoff', '2026-09-01'));

    CarbonImmutable::setTestNow();
});

test('report supports filters sorting pagination and needs attention', function () {
    ['user' => $user, 'employee' => $employee] = authorizeCrewMovementHistoryReport();

    CrewAssignment::factory()->count(26)->forEmployee($employee)->sequence(
        fn ($sequence) => [
            'assignment_no' => 'CA-PAGE-'.str_pad((string) ($sequence->index + 1), 3, '0', STR_PAD_LEFT),
            'status' => CrewAssignmentStatus::Completed,
            'started_at' => now()->subDays(30),
            'closed_at' => now(),
        ],
    )->create();

    CrewAssignment::factory()->forEmployee($employee)->create([
        'assignment_no' => 'CA-ATTENTION',
        'status' => CrewAssignmentStatus::Draft,
        'created_at' => now()->subDays(8),
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index', [
            'status' => 'completed',
            'sort' => 'assignment_no',
            'direction' => 'asc',
            'per_page' => 25,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 25)
            ->where('assignments.0.assignment_no', 'CA-PAGE-001')
            ->where('pagination.total', 26)
            ->where('pagination.last_page', 2));

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index', [
            'needs_attention' => '1',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.assignment_no', 'CA-ATTENTION')
            ->where('assignments.0.needs_attention', true));
});

test('report paginates one thousand assignments without per row queries', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = authorizeCrewMovementHistoryReport();

    CrewAssignment::factory()
        ->count(1000)
        ->forEmployee($employee)
        ->create([
            'rank_id' => $rank->id,
            'client_id' => null,
            'vessel_id' => null,
            'company_visa_type_id' => null,
        ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $paginator = (new CrewMovementHistoryQuery(
        $company->id,
        new CrewMovementHistoryFilters,
        $company->timezone,
    ))->paginate(25);

    expect($paginator->total())->toBe(1000)
        ->and($paginator->items())->toHaveCount(25)
        ->and(count(DB::getQueryLog()))->toBeLessThanOrEqual(10);
});
