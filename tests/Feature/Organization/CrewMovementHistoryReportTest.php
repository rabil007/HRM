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
use App\Models\Hotel;
use App\Models\Vessel;
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
            ->has('assignments.0', fn (Assert $row) => $row
                ->hasAll([
                    'id',
                    'assignment_no',
                    'employee.id',
                    'employee.employee_no',
                    'employee.name',
                    'rank',
                    'vessel',
                    'client',
                    'status',
                    'status_label',
                    'current_phase.code',
                    'current_phase.label',
                    'current_phase.status',
                    'source',
                    'source_label',
                    'planned_travel_in',
                    'planned_arrival',
                    'actual_arrival',
                    'planned_join',
                    'planned_signoff',
                    'planned_travel_home',
                    'has_legacy_phases',
                    'pre_mobilisation.periods',
                    'travel_in.periods',
                    'join_standby.periods',
                    'join_standby.total_days',
                    'join_standby.total_days_label',
                    'training.periods',
                    'training.details',
                    'ready_to_join.periods',
                    'on_vessel.periods',
                    'on_vessel.actual_join',
                    'on_vessel.actual_disembarkation',
                    'demob_standby.periods',
                    'demob_standby.total_days',
                    'demob_standby.total_days_label',
                    'home_redeploy.periods',
                    'assignment_started',
                    'assignment_closed',
                    'total_assignment_days',
                    'total_assignment_days_label',
                    'payroll_days.sign_on_standby.periods',
                    'payroll_days.sign_on_standby.total_days',
                    'payroll_days.onsite.periods',
                    'payroll_days.onsite.total_days',
                    'payroll_days.sign_off_standby.periods',
                    'payroll_days.sign_off_standby.total_days',
                    'payroll_days.total_days',
                    'remarks',
                    'needs_attention',
                    'warnings',
                    'has_corrections',
                    'correction_count',
                    'last_corrected_at',
                    'has_pending_corrections',
                    'company_timezone',
                ])
                ->etc())
            ->has('assignments.0.join_standby.periods', 2)
            ->has('assignments.0.training.periods', 2)
            ->where('assignments.0.training.details.0', 'ABC — BOSIET')
            ->where('assignments.0.training.details.1', 'XYZ — Refresher')
            ->where('assignments.0.on_vessel.actual_join', '2026-07-24')
            ->where('assignments.0.on_vessel.actual_disembarkation', null)
            ->where('assignments.0.payroll_days.sign_on_standby.total_days', 9)
            ->where('assignments.0.payroll_days.onsite.total_days', 2)
            ->where('assignments.0.payroll_days.sign_off_standby.total_days', 0)
            ->where('assignments.0.payroll_days.total_days', 11)
            ->where('assignments.0.planned_signoff', '2026-09-01')
            ->where('assignments.0.has_legacy_phases', false));

    CarbonImmutable::setTestNow();
});

test('report filter options exclude legacy current phases and legacy rows are flagged', function () {
    ['user' => $user, 'employee' => $employee] = authorizeCrewMovementHistoryReport();

    $legacyAssignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create(['assignment_no' => 'CA-LEGACY-001']);

    CrewAssignmentPhase::factory()->forAssignment($legacyAssignment)->create([
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-01-03',
        'actual_end_at' => '2026-01-04',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index', ['search' => 'CA-LEGACY-001']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('filter_options.phases', 6)
            ->where('filter_options.phases', fn ($phases) => collect($phases)->pluck('value')->all() === [
                'p0',
                'p2a',
                'p2b',
                'p4',
                'p5',
                'p6',
            ])
            ->where('assignments.0.has_legacy_phases', true)
            ->where('assignments.0.travel_in.periods.0.start', '2026-01-03'));
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
        // Count includes pagination + constant eager loads for phases, stays,
        // linked assignments, training, and corrections (no per-row queries).
        ->and(count(DB::getQueryLog()))->toBeLessThanOrEqual(30);
});

test('needs attention uses authoritative attention query not stale p4 threshold', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = authorizeCrewMovementHistoryReport();

    $staleP4 = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'assignment_no' => 'CA-STALE-P4',
        'rank_id' => $rank->id,
        'vessel_id' => Vessel::factory()->create(['company_id' => $company->id])->id,
        'tour_of_duty_days' => 90,
        'planned_signoff_at' => now($company->timezone)->addDays(45)->toDateString(),
        'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
    ]);
    $phase = CrewAssignmentPhase::factory()->forAssignment($staleP4)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => now()->subDays(20),
        'actual_end_at' => null,
    ]);
    $staleP4->update(['current_phase_id' => $phase->id]);

    $tourDue = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'assignment_no' => 'CA-TOUR-DUE',
        'rank_id' => $rank->id,
        'vessel_id' => Vessel::factory()->create(['company_id' => $company->id])->id,
        'tour_of_duty_days' => 60,
        'planned_signoff_at' => now($company->timezone)->addDays(5)->toDateString(),
        'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
    ]);
    $tourPhase = CrewAssignmentPhase::factory()->forAssignment($tourDue)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => now()->subDays(55),
        'actual_end_at' => null,
    ]);
    $tourDue->update(['current_phase_id' => $tourPhase->id]);

    $query = new CrewMovementHistoryQuery(
        $company->id,
        new CrewMovementHistoryFilters(needsAttention: '1'),
        $company->timezone,
        $user,
    );
    $summary = $query->summary();
    $rows = collect($query->paginate(25)->items());

    expect($rows->pluck('assignment_no')->all())->toContain('CA-TOUR-DUE')
        ->and($rows->pluck('assignment_no')->all())->not->toContain('CA-STALE-P4')
        ->and($rows->firstWhere('assignment_no', 'CA-TOUR-DUE')['needs_attention'])->toBeTrue()
        ->and($summary['needs_attention'])->toBe($rows->count());

    CarbonImmutable::setTestNow();
});

test('report filters by accommodation hotel and planned arrival range', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = authorizeCrewMovementHistoryReport();

    $matched = CrewAssignment::factory()->forEmployee($employee)->create([
        'assignment_no' => 'CA-HOTEL-MATCH',
        'planned_arrival_at' => '2026-09-12',
    ]);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    CrewAccommodationStay::factory()->create([
        'crew_assignment_id' => $matched->id,
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-10',
        'check_out_date' => '2026-09-14',
    ]);

    CrewAssignment::factory()->forEmployee($employee)->create([
        'assignment_no' => 'CA-HOTEL-MISS',
        'planned_arrival_at' => '2026-08-01',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index', [
            'hotel_id' => $hotel->id,
            'planned_arrival_from' => '2026-09-01',
            'planned_arrival_to' => '2026-09-30',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.assignment_no', 'CA-HOTEL-MATCH')
            ->has('filter_options.hotels')
            ->has('filter_options.tour_statuses'));
});

test('actual arrival filter uses p2a with completed p1 fallback and modern precedence', function () {
    ['user' => $user, 'employee' => $employee] = authorizeCrewMovementHistoryReport();

    $modern = CrewAssignment::factory()->forEmployee($employee)->create([
        'assignment_no' => 'CA-ARRIVAL-P2A',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($modern)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-09-10 09:00:00',
        'actual_end_at' => null,
    ]);

    $legacy = CrewAssignment::factory()->forEmployee($employee)->create([
        'assignment_no' => 'CA-ARRIVAL-P1',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($legacy)->create([
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-01-03 08:00:00',
        'actual_end_at' => '2026-01-04 11:00:00',
    ]);

    $both = CrewAssignment::factory()->forEmployee($employee)->create([
        'assignment_no' => 'CA-ARRIVAL-BOTH',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($both)->create([
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-01-03 08:00:00',
        'actual_end_at' => '2026-01-04 11:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($both)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-01-08 09:00:00',
        'actual_end_at' => '2026-01-09 09:00:00',
    ]);

    $repeatedP2a = CrewAssignment::factory()->forEmployee($employee)->create([
        'assignment_no' => 'CA-ARRIVAL-P2A-REPEAT',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($repeatedP2a)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-09-10 08:00:00',
        'actual_end_at' => '2026-09-12 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($repeatedP2a)->create([
        'phase_code' => CrewPhaseCode::Training,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-09-13 08:00:00',
        'actual_end_at' => '2026-09-14 17:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($repeatedP2a)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-09-20 08:00:00',
        'actual_end_at' => '2026-09-21 08:00:00',
    ]);

    $repeatedP1 = CrewAssignment::factory()->forEmployee($employee)->create([
        'assignment_no' => 'CA-ARRIVAL-P1-REPEAT',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($repeatedP1)->create([
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-01-03 08:00:00',
        'actual_end_at' => '2026-01-04 11:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($repeatedP1)->create([
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-01-07 08:00:00',
        'actual_end_at' => '2026-01-08 11:00:00',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index', [
            'actual_arrival_from' => '2026-09-10',
            'actual_arrival_to' => '2026-09-10',
            'search' => 'CA-ARRIVAL-P2A',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 2)
            ->where('assignments', fn ($assignments) => collect($assignments)->pluck('assignment_no')->sort()->values()->all() === [
                'CA-ARRIVAL-P2A',
                'CA-ARRIVAL-P2A-REPEAT',
            ])
            ->where('assignments.0.actual_arrival', '2026-09-10'));

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index', [
            'actual_arrival_from' => '2026-09-20',
            'actual_arrival_to' => '2026-09-20',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 0));

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index', [
            'actual_arrival_from' => '2026-01-04',
            'actual_arrival_to' => '2026-01-04',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 2)
            ->where('assignments', fn ($assignments) => collect($assignments)->pluck('assignment_no')->sort()->values()->all() === [
                'CA-ARRIVAL-P1',
                'CA-ARRIVAL-P1-REPEAT',
            ]));

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index', [
            'actual_arrival_from' => '2026-01-08',
            'actual_arrival_to' => '2026-01-08',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.assignment_no', 'CA-ARRIVAL-BOTH')
            ->where('assignments.0.actual_arrival', '2026-01-08'));
});

test('report query count stays constant when page size increases', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = authorizeCrewMovementHistoryReport();

    CrewAssignment::factory()
        ->count(25)
        ->forEmployee($employee)
        ->create([
            'rank_id' => $rank->id,
            'client_id' => null,
            'vessel_id' => null,
        ]);

    $measure = function (int $perPage) use ($company): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $paginator = (new CrewMovementHistoryQuery(
            $company->id,
            new CrewMovementHistoryFilters,
            $company->timezone,
        ))->paginate($perPage);

        expect($paginator->items())->toHaveCount($perPage);

        return count(DB::getQueryLog());
    };

    $queriesForFive = $measure(5);
    $queriesForTwentyFive = $measure(25);

    expect($queriesForFive)->toBeLessThanOrEqual(30)
        ->and($queriesForTwentyFive)->toBeLessThanOrEqual(30)
        ->and(abs($queriesForTwentyFive - $queriesForFive))->toBeLessThanOrEqual(2);
});
