<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Position;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewPlanning\LegacyPlannedMigrationCandidate;
use App\Support\CrewPlanning\MigrateLegacyPlannedAssignments;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->migrator = app(MigrateLegacyPlannedAssignments::class);
    $this->movements = app(CrewMovementService::class);
});

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Position, position: Position, vessel: Vessel, planned: CrewAssignment}
 */
function makeLegacyPlannedFixture(array $overrides = []): array
{
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'position' => $position] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Legacy Planned Vessel', $company);

    $planned = app(CrewMovementService::class)->createPlanned(
        (int) $company->id,
        (int) $employee->id,
        array_merge([
            'vessel_id' => $vessel->id,
            'position_id' => $position->id,
            'planned_arrival_at' => '2027-05-01',
            'planned_join_at' => '2027-05-05',
            'planned_signoff_at' => '2027-08-05',
            'remarks' => 'Legacy planned remarks',
        ], $overrides),
        $user->id,
    );

    return compact('user', 'company', 'employee', 'rank', 'position', 'vessel', 'planned');
}

test('command requires company or all-companies', function () {
    $this->artisan('crew-planning:migrate-legacy-planned')
        ->assertFailed();
});

test('dry-run finds legacy Planned assignments, reports mapping, and writes nothing', function () {
    $fixture = makeLegacyPlannedFixture();
    $planned = $fixture['planned'];
    $company = $fixture['company'];

    $activityCountBefore = Activity::query()->count();
    $planningCountBefore = CrewPlanningAssignment::query()->count();

    $report = $this->migrator->inspect([(int) $company->id]);

    expect($report->scanned())->toBe(1)
        ->and($report->convertible())->toBe(1)
        ->and($report->blocked())->toBe(0)
        ->and($report->applied)->toBeFalse();

    $candidate = $report->candidates[0];
    expect($candidate->assignmentId)->toBe((int) $planned->id)
        ->and($candidate->assignmentNo)->toBe($planned->assignment_no)
        ->and($candidate->employeeId)->toBe((int) $fixture['employee']->id)
        ->and($candidate->vesselId)->toBe((int) $fixture['vessel']->id)
        ->and($candidate->positionId)->toBe((int) $fixture['position']->id)
        ->and($candidate->plannedArrivalDate)->toBe('2027-05-01')
        ->and($candidate->plannedJoinDate)->toBe('2027-05-05')
        ->and($candidate->plannedLeaveDate)->toBe('2027-08-05')
        ->and($candidate->remarks)->toBe('Legacy planned remarks')
        ->and($candidate->migrationStatus)->toBe(LegacyPlannedMigrationCandidate::STATUS_CONVERTIBLE)
        ->and($candidate->disposition)->toBe(LegacyPlannedMigrationCandidate::DISPOSITION_CREATE);

    expect($planned->fresh()->status)->toBe(CrewAssignmentStatus::Planned)
        ->and(CrewPlanningAssignment::query()->count())->toBe($planningCountBefore)
        ->and(Activity::query()->count())->toBe($activityCountBefore);

    $this->artisan('crew-planning:migrate-legacy-planned', [
        '--company' => $company->id,
    ])->assertSuccessful();

    expect($planned->fresh()->status)->toBe(CrewAssignmentStatus::Planned);
});

test('apply creates named planning, preserves mapping, retires Planned without operational actuals', function () {
    $fixture = makeLegacyPlannedFixture([
        'remarks' => 'Keep these notes',
    ]);
    $company = $fixture['company'];
    $planned = $fixture['planned'];

    $report = $this->migrator->apply([(int) $company->id], null, $fixture['user']);

    expect($report->abortedDueToBlockers)->toBeFalse()
        ->and($report->migrated())->toBe(1)
        ->and($report->namedPlanningCreated)->toBe(1)
        ->and($report->legacyPlannedRetired)->toBe(1)
        ->and($report->remainingPlannedCount)->toBe(0)
        ->and($report->isComplete())->toBeTrue();

    $planning = CrewPlanningAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $fixture['employee']->id)
        ->first();

    expect($planning)->not->toBeNull()
        ->and($planning->vessel_id)->toBe($fixture['vessel']->id)
        ->and($planning->position_id)->toBe($fixture['position']->id)
        ->and($planning->planned_arrival_date?->toDateString())->toBe('2027-05-01')
        ->and($planning->planned_join_date?->toDateString())->toBe('2027-05-05')
        ->and($planning->planned_leave_date?->toDateString())->toBe('2027-08-05')
        ->and($planning->notes)->toBe('Keep these notes')
        ->and($planning->crew_assignment_id)->toBeNull();

    $retired = $planned->fresh(['phases', 'currentPhase']);
    expect($retired->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($retired->remarks)->toBe('Keep these notes')
        ->and($retired->started_at)->toBeNull()
        ->and($retired->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($retired->currentPhase?->status)->toBe(CrewPhaseStatus::Cancelled)
        ->and($retired->currentPhase?->actual_start_at)->toBeNull()
        ->and($retired->currentPhase?->actual_end_at)->toBeNull()
        ->and($retired->phases)->toHaveCount(1)
        ->and(EmployeeSeaService::query()->whereIn(
            'crew_assignment_phase_id',
            $retired->phases->pluck('id'),
        )->count())->toBe(0);

    expect(Activity::query()
        ->where('description', MigrateLegacyPlannedAssignments::ACTIVITY)
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $planned->id)
        ->exists())->toBeTrue();
});

test('apply preserves relief relationship without touching the source assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $onboardEmployee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Relief Source Vessel', $company);
    $sourceOnVessel = makeActiveOnVesselAssignment($company, $onboardEmployee, $rank, $vessel, [
        'planned_signoff_at' => '2027-12-01',
    ]);
    $sourceSignOff = $sourceOnVessel->fresh()->planned_signoff_at?->toIso8601String();

    $reliever = Employee::factory()->forCompany($company)->create([
        'position_id' => $rank->id,
        'status' => 'active',
    ]);
    // Mirror existing Planned-relief fixtures: attach relief after createPlanned.
    $plannedWithRelief = $this->movements->createPlanned(
        (int) $company->id,
        (int) $reliever->id,
        [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'planned_arrival_at' => '2027-06-01',
            'planned_join_at' => '2027-06-10',
            'planned_signoff_at' => '2027-09-10',
            'remarks' => 'Relief remarks',
        ],
        $user->id,
    );
    $plannedWithRelief->update(['relieves_crew_assignment_id' => $sourceOnVessel->id]);

    $report = $this->migrator->apply([(int) $company->id], (int) $plannedWithRelief->id, $user);

    expect($report->failed())->toBe(0)
        ->and($report->migrated())->toBe(1)
        ->and($report->namedPlanningCreated)->toBe(1);

    $planning = CrewPlanningAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $reliever->id)
        ->first();

    expect($planning)->not->toBeNull()
        ->and($planning->relieves_crew_assignment_id)->toBe($sourceOnVessel->id)
        ->and($planning->notes)->toBe('Relief remarks');

    $source = $sourceOnVessel->fresh(['currentPhase', 'phases']);
    expect($source->status)->toBe(CrewAssignmentStatus::Active)
        ->and($source->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($source->planned_signoff_at?->toIso8601String())->toBe($sourceSignOff)
        ->and(EmployeeSeaService::query()->whereIn(
            'crew_assignment_phase_id',
            $source->phases->pluck('id'),
        )->count())->toBe(0);
});

test('apply reuses linked vacant planning without creating a duplicate', function () {
    $fixture = makeLegacyPlannedFixture();
    $company = $fixture['company'];
    $planned = $fixture['planned'];

    $linkedVacant = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $fixture['vessel']->id,
        'position_id' => $fixture['position']->id,
        'employee_id' => null,
        'crew_assignment_id' => $planned->id,
        'planned_join_date' => '2027-05-05',
        'planned_leave_date' => '2027-08-05',
        'notes' => 'old vacant notes',
    ]);

    $planningCountBefore = CrewPlanningAssignment::query()->where('company_id', $company->id)->count();

    $report = $this->migrator->apply([(int) $company->id]);

    expect($report->migrated())->toBe(1)
        ->and($report->namedPlanningCreated)->toBe(0)
        ->and($report->existingPlanningReused)->toBe(1)
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe($planningCountBefore);

    $linkedVacant->refresh();
    expect($linkedVacant->employee_id)->toBe($fixture['employee']->id)
        ->and($linkedVacant->crew_assignment_id)->toBeNull()
        ->and($linkedVacant->notes)->toBe('Legacy planned remarks')
        ->and($linkedVacant->planned_arrival_date?->toDateString())->toBe('2027-05-01')
        ->and($planned->fresh()->status)->toBe(CrewAssignmentStatus::Cancelled);
});

test('apply reuses exactly one equivalent unlinked named planning', function () {
    $fixture = makeLegacyPlannedFixture();
    $company = $fixture['company'];
    $planned = $fixture['planned'];

    $equivalent = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $fixture['vessel']->id,
        'position_id' => $fixture['position']->id,
        'employee_id' => $fixture['employee']->id,
        'crew_assignment_id' => null,
        'planned_arrival_date' => '2027-05-01',
        'planned_join_date' => '2027-05-05',
        'planned_leave_date' => '2027-08-05',
        'notes' => 'already there',
    ]);

    $planningCountBefore = CrewPlanningAssignment::query()->where('company_id', $company->id)->count();

    $inspect = $this->migrator->inspect([(int) $company->id]);
    expect($inspect->candidates[0]->migrationStatus)->toBe(LegacyPlannedMigrationCandidate::STATUS_ALREADY_REPRESENTED)
        ->and($inspect->candidates[0]->planningAssignmentId)->toBe($equivalent->id);

    $report = $this->migrator->apply([(int) $company->id]);

    expect($report->migrated())->toBe(1)
        ->and($report->namedPlanningCreated)->toBe(0)
        ->and($report->existingPlanningReused)->toBe(1)
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe($planningCountBefore)
        ->and($equivalent->fresh()->notes)->toBe('already there')
        ->and($planned->fresh()->status)->toBe(CrewAssignmentStatus::Cancelled);
});

test('missing required data and operational actuals are blocked', function () {
    $fixture = makeLegacyPlannedFixture();
    $company = $fixture['company'];

    // Force missing join via direct update (bypassing createPlanned validation).
    $broken = $fixture['planned'];
    $broken->update(['planned_join_at' => null]);

    $report = $this->migrator->inspect([(int) $company->id]);
    expect($report->blocked())->toBe(1)
        ->and($report->candidates[0]->blockers)->toContain('missing_expected_join');

    $fixture2 = makeLegacyPlannedFixture();
    $withActuals = $fixture2['planned'];
    $withActuals->currentPhase?->update([
        'actual_start_at' => now(),
        'status' => CrewPhaseStatus::Active,
    ]);
    $withActuals->update(['started_at' => now()]);

    $report2 = $this->migrator->inspect([(int) $fixture2['company']->id]);
    expect($report2->candidates[0]->migrationStatus)->toBe(LegacyPlannedMigrationCandidate::STATUS_BLOCKED)
        ->and($report2->candidates[0]->blockers)->toContain('unexpected_started_at')
        ->and($report2->candidates[0]->blockers)->toContain('unexpected_phase_status')
        ->and($report2->candidates[0]->blockers)->toContain('unexpected_phase_actuals');
});

test('migration blocks unexpected P0 phase statuses and non-P0 or multiple phases', function () {
    foreach ([
        CrewPhaseStatus::Active,
        CrewPhaseStatus::Completed,
        CrewPhaseStatus::Cancelled,
        CrewPhaseStatus::Corrected,
    ] as $status) {
        $fixture = makeLegacyPlannedFixture();
        $fixture['planned']->currentPhase?->update(['status' => $status]);

        $report = $this->migrator->inspect([(int) $fixture['company']->id]);
        expect($report->candidates[0]->blockers)
            ->toContain('unexpected_phase_status')
            ->and($fixture['planned']->fresh()->status)->toBe(CrewAssignmentStatus::Planned);
    }

    $nonP0 = makeLegacyPlannedFixture();
    $nonP0['planned']->currentPhase?->update(['phase_code' => CrewPhaseCode::JoinStandby]);
    expect($this->migrator->inspect([(int) $nonP0['company']->id])->candidates[0]->blockers)
        ->toContain('unexpected_non_p0_phase');

    $multi = makeLegacyPlannedFixture();
    CrewAssignmentPhase::query()->create([
        'company_id' => $multi['company']->id,
        'crew_assignment_id' => $multi['planned']->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Planned,
    ]);
    expect($this->migrator->inspect([(int) $multi['company']->id])->candidates[0]->blockers)
        ->toContain('unexpected_multiple_phases');
});

test('apply reuses exact compatible linked named planning without duplicate', function () {
    $fixture = makeLegacyPlannedFixture();
    $company = $fixture['company'];
    $planned = $fixture['planned'];

    $linkedNamed = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $fixture['vessel']->id,
        'position_id' => $fixture['position']->id,
        'employee_id' => $fixture['employee']->id,
        'crew_assignment_id' => $planned->id,
        'planned_arrival_date' => '2027-05-01',
        'planned_join_date' => '2027-05-05',
        'planned_leave_date' => '2027-08-05',
        'notes' => 'linked named notes',
    ]);

    $planningCountBefore = CrewPlanningAssignment::query()->where('company_id', $company->id)->count();

    $inspect = $this->migrator->inspect([(int) $company->id]);
    expect($inspect->candidates[0]->disposition)
        ->toBe(LegacyPlannedMigrationCandidate::DISPOSITION_REUSE_LINKED_NAMED);

    $report = $this->migrator->apply([(int) $company->id]);

    expect($report->migrated())->toBe(1)
        ->and($report->namedPlanningCreated)->toBe(0)
        ->and($report->existingPlanningReused)->toBe(1)
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe($planningCountBefore);

    $linkedNamed->refresh();
    expect($linkedNamed->crew_assignment_id)->toBeNull()
        ->and($linkedNamed->employee_id)->toBe($fixture['employee']->id)
        ->and($linkedNamed->notes)->toBe('linked named notes')
        ->and($planned->fresh()->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($planned->fresh()->planningAssignment)->toBeNull();
});

test('linked named planning with mismatched vessel or dates is blocked with no writes', function () {
    $fixture = makeLegacyPlannedFixture();
    $otherVessel = makeCrewMovementVessel('Mismatch Vessel', $fixture['company']);

    CrewPlanningAssignment::query()->create([
        'company_id' => $fixture['company']->id,
        'vessel_id' => $otherVessel->id,
        'position_id' => $fixture['position']->id,
        'employee_id' => $fixture['employee']->id,
        'crew_assignment_id' => $fixture['planned']->id,
        'planned_arrival_date' => '2027-05-01',
        'planned_join_date' => '2027-05-05',
        'planned_leave_date' => '2027-08-05',
    ]);

    $planningBefore = CrewPlanningAssignment::query()->where('company_id', $fixture['company']->id)->count();
    $report = $this->migrator->apply([(int) $fixture['company']->id]);

    expect($report->abortedDueToBlockers)->toBeTrue()
        ->and($report->candidates[0]->blockers)->toContain('linked_named_planning_mismatch')
        ->and($report->migrated())->toBe(0)
        ->and($fixture['planned']->fresh()->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($fixture['planned']->fresh()->planningAssignment?->crew_assignment_id)->toBe($fixture['planned']->id)
        ->and(CrewPlanningAssignment::query()->where('company_id', $fixture['company']->id)->count())->toBe($planningBefore);
});

test('cross-company relationships and ambiguous equivalents are blocked', function () {
    $fixtureA = makeLegacyPlannedFixture();
    $fixtureB = makeLegacyPlannedFixture();

    // Point relief at company B assignment.
    $fixtureA['planned']->update([
        'relieves_crew_assignment_id' => $fixtureB['planned']->id,
    ]);

    $report = $this->migrator->inspect([(int) $fixtureA['company']->id]);
    expect($report->candidates[0]->blockers)->toContain('cross_company_relief_assignment');

    $fixture = makeLegacyPlannedFixture();
    CrewPlanningAssignment::query()->create([
        'company_id' => $fixture['company']->id,
        'vessel_id' => $fixture['vessel']->id,
        'position_id' => $fixture['position']->id,
        'employee_id' => $fixture['employee']->id,
        'crew_assignment_id' => null,
        'planned_arrival_date' => '2027-05-01',
        'planned_join_date' => '2027-05-05',
        'planned_leave_date' => '2027-08-05',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $fixture['company']->id,
        'vessel_id' => $fixture['vessel']->id,
        'position_id' => $fixture['position']->id,
        'employee_id' => $fixture['employee']->id,
        'crew_assignment_id' => null,
        'planned_arrival_date' => '2027-05-01',
        'planned_join_date' => '2027-05-05',
        'planned_leave_date' => '2027-08-05',
    ]);

    $ambiguous = $this->migrator->inspect([(int) $fixture['company']->id]);
    expect($ambiguous->candidates[0]->blockers)->toContain('ambiguous_equivalent_planning_matches');
});

test('apply aborts entirely when any blocker exists', function () {
    $good = makeLegacyPlannedFixture();
    $bad = makeLegacyPlannedFixture();
    $bad['planned']->update(['vessel_id' => null]);

    // Same company: move bad into good company by recreating scenario in one company.
    $company = $good['company'];
    $brokenInSameCompany = $this->movements->createPlanned(
        (int) $company->id,
        (int) Employee::factory()->forCompany($company)->create([
            'position_id' => $good['position']->id,
            'status' => 'active',
        ])->id,
        [
            'vessel_id' => $good['vessel']->id,
            'position_id' => $good['position']->id,
            'planned_join_at' => '2028-01-01',
            'planned_signoff_at' => '2028-03-01',
        ],
        $good['user']->id,
    );
    $brokenInSameCompany->update(['planned_signoff_at' => null]);

    $planningBefore = CrewPlanningAssignment::query()->where('company_id', $company->id)->count();
    $report = $this->migrator->apply([(int) $company->id]);

    expect($report->abortedDueToBlockers)->toBeTrue()
        ->and($report->migrated())->toBe(0)
        ->and($good['planned']->fresh()->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($brokenInSameCompany->fresh()->status)->toBe(CrewAssignmentStatus::Planned)
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe($planningBefore);

    Artisan::call('crew-planning:migrate-legacy-planned', [
        '--company' => $company->id,
        '--apply' => true,
    ]);
    expect(Artisan::output())->toContain('Apply aborted');
});

test('apply is idempotent and does not create duplicates on second run', function () {
    $fixture = makeLegacyPlannedFixture();
    $company = $fixture['company'];

    $first = $this->migrator->apply([(int) $company->id]);
    expect($first->migrated())->toBe(1)->and($first->remainingPlannedCount)->toBe(0);

    $planningCount = CrewPlanningAssignment::query()->where('company_id', $company->id)->count();

    $second = $this->migrator->apply([(int) $company->id]);
    expect($second->scanned())->toBe(0)
        ->and($second->migrated())->toBe(0)
        ->and($second->remainingPlannedCount)->toBe(0)
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe($planningCount);
});

test('company A migration never touches company B data', function () {
    $a = makeLegacyPlannedFixture();
    $b = makeLegacyPlannedFixture();

    $report = $this->migrator->apply([(int) $a['company']->id]);

    expect($report->migrated())->toBe(1)
        ->and($a['planned']->fresh()->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($b['planned']->fresh()->status)->toBe(CrewAssignmentStatus::Planned)
        ->and(CrewPlanningAssignment::query()->where('company_id', $b['company']->id)->count())->toBe(0);
});

test('migrated planning reserves availability exactly once without double booking', function () {
    $fixture = makeLegacyPlannedFixture();
    $company = $fixture['company'];
    $employee = $fixture['employee'];

    $this->migrator->apply([(int) $company->id]);

    expect(CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('status', CrewAssignmentStatus::Planned)
        ->count())->toBe(0);

    $timezone = CompanyTimezone::forCompanyId((int) $company->id);
    $result = (new CrewAssignmentConflictEvaluator)->evaluate(new CrewAssignmentConflictContext(
        companyId: (int) $company->id,
        employeeId: (int) $employee->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2027-05-05', $timezone)->startOfDay(),
        plannedSignoffAt: CarbonImmutable::parse('2027-08-05', $timezone)->endOfDay(),
        plannedArrivalAt: CarbonImmutable::parse('2027-05-01', $timezone)->startOfDay(),
        vesselId: (int) $fixture['vessel']->id,
        positionId: (int) $fixture['position']->id,
    ));

    expect($result->blocking)->toBeTrue()
        ->and($result->code)->toBe('planned_planned_overlap');

    // Only one planning reservation for the employee in range.
    expect(CrewPlanningAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->whereNull('crew_assignment_id')
        ->count())->toBe(1);
});

test('conflicting linked named planning is blocked', function () {
    $fixture = makeLegacyPlannedFixture();
    $otherEmployee = Employee::factory()->forCompany($fixture['company'])->create([
        'position_id' => $fixture['position']->id,
        'status' => 'active',
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $fixture['company']->id,
        'vessel_id' => $fixture['vessel']->id,
        'position_id' => $fixture['position']->id,
        'employee_id' => $otherEmployee->id,
        'crew_assignment_id' => $fixture['planned']->id,
        'planned_join_date' => '2027-05-05',
        'planned_leave_date' => '2027-08-05',
    ]);

    $report = $this->migrator->inspect([(int) $fixture['company']->id]);
    expect($report->candidates[0]->blockers)->toContain('linked_planning_employee_conflict');
});

test('sea service on planned phase blocks migration', function () {
    $fixture = makeLegacyPlannedFixture();
    $phase = $fixture['planned']->currentPhase;
    expect($phase)->not->toBeNull();

    EmployeeSeaService::factory()->forEmployee($fixture['employee'])->create([
        'crew_assignment_phase_id' => $phase->id,
        'start_date' => '2027-05-05',
        'end_date' => null,
    ]);

    $report = $this->migrator->inspect([(int) $fixture['company']->id]);
    expect($report->candidates[0]->blockers)->toContain('unexpected_sea_service');
});
