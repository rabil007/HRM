<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewPlanning\RetireSoftDeletedPlannedAssignments;
use App\Support\CrewPlanning\SoftDeletedPlannedRetirementCandidate;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->retirer = app(RetireSoftDeletedPlannedAssignments::class);
    $this->movements = app(CrewMovementService::class);
});

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Position, position: Position, vessel: Vessel, planned: CrewAssignment, deletedAt: string}
 */
function makeSoftDeletedPlannedTombstone(array $overrides = []): array
{
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'position' => $position] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Soft-Deleted Planned Vessel', $company);

    $planned = app(CrewMovementService::class)->createPlanned(
        (int) $company->id,
        (int) $employee->id,
        array_merge([
            'vessel_id' => $vessel->id,
            'position_id' => $position->id,
            'planned_arrival_at' => '2027-05-01',
            'planned_join_at' => '2027-05-05',
            'planned_signoff_at' => '2027-08-05',
            'remarks' => 'Soft-deleted planned remarks',
        ], $overrides),
        $user->id,
    );

    $deletedAt = '2026-09-29 13:44:46';
    $planned->delete();
    // Pin historical deletion timestamp (production tombstone shape).
    DB::table('crew_assignments')->where('id', $planned->id)->update([
        'deleted_at' => $deletedAt,
        'updated_at' => '2026-10-01 10:20:47',
    ]);

    $planned = CrewAssignment::withTrashed()->whereKey($planned->id)->firstOrFail();

    return compact('user', 'company', 'employee', 'rank', 'position', 'vessel', 'planned', 'deletedAt');
}

test('command requires company scope', function () {
    $this->artisan('crew-planning:retire-soft-deleted-planned')
        ->assertFailed();
});

test('dry-run discovers soft-deleted Planned tombstone and writes nothing', function () {
    $fixture = makeSoftDeletedPlannedTombstone();
    $planned = $fixture['planned'];
    $company = $fixture['company'];

    $activityCountBefore = Activity::query()->count();
    $planningCountBefore = CrewPlanningAssignment::query()->count();
    $deletedAtBefore = $planned->deleted_at?->toDateTimeString();

    $report = $this->retirer->inspect([(int) $company->id]);

    expect($report->scanned())->toBe(1)
        ->and($report->convertible())->toBe(1)
        ->and($report->blocked())->toBe(0)
        ->and($report->applied)->toBeFalse();

    $candidate = $report->candidates[0];
    expect($candidate->assignmentId)->toBe((int) $planned->id)
        ->and($candidate->assignmentNo)->toBe($planned->assignment_no)
        ->and($candidate->migrationStatus)->toBe(SoftDeletedPlannedRetirementCandidate::STATUS_CONVERTIBLE)
        ->and($candidate->disposition)->toBe(SoftDeletedPlannedRetirementCandidate::DISPOSITION_RETIRE_TO_CANCELLED)
        ->and($candidate->deletedAt)->toBe($fixture['deletedAt']);

    $fresh = CrewAssignment::withTrashed()->whereKey($planned->id)->firstOrFail();
    expect($fresh->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($fresh->trashed())->toBeTrue()
        ->and($fresh->deleted_at?->toDateTimeString())->toBe($deletedAtBefore)
        ->and(CrewPlanningAssignment::query()->count())->toBe($planningCountBefore)
        ->and(Activity::query()->count())->toBe($activityCountBefore);

    $this->artisan('crew-planning:retire-soft-deleted-planned', [
        '--company' => $company->id,
    ])->assertSuccessful();

    $after = CrewAssignment::withTrashed()->whereKey($planned->id)->firstOrFail();
    expect($after->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($after->deleted_at?->toDateTimeString())->toBe($deletedAtBefore);
});

test('apply retires soft-deleted Planned to Cancelled without restoring or creating Planning', function () {
    $fixture = makeSoftDeletedPlannedTombstone();
    $company = $fixture['company'];
    $planned = $fixture['planned'];
    $planningCountBefore = CrewPlanningAssignment::query()->count();

    $report = $this->retirer->apply([(int) $company->id], null, $fixture['user']);

    expect($report->abortedDueToBlockers)->toBeFalse()
        ->and($report->retired())->toBe(1)
        ->and($report->remainingSoftDeletedPlannedCount)->toBe(0);

    $retired = CrewAssignment::withTrashed()->with(['phases' => fn ($q) => $q->withTrashed()])->whereKey($planned->id)->firstOrFail();

    expect($retired->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($retired->trashed())->toBeTrue()
        ->and($retired->deleted_at?->toDateTimeString())->toBe($fixture['deletedAt'])
        ->and($retired->closed_at?->toDateTimeString())->toBe($fixture['deletedAt'])
        ->and($retired->remarks)->toBe('Soft-deleted planned remarks')
        ->and(CrewAssignment::query()->whereKey($planned->id)->exists())->toBeFalse()
        ->and(CrewPlanningAssignment::query()->count())->toBe($planningCountBefore);

    $phase = $retired->phases->first();
    expect($phase)->not->toBeNull()
        ->and($phase->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($phase->status)->toBe(CrewPhaseStatus::Cancelled)
        ->and($phase->actual_start_at)->toBeNull()
        ->and($phase->actual_end_at)->toBeNull();

    expect(Activity::query()
        ->where('description', RetireSoftDeletedPlannedAssignments::ACTIVITY)
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $planned->id)
        ->exists())->toBeTrue();

    $activity = Activity::query()
        ->where('description', RetireSoftDeletedPlannedAssignments::ACTIVITY)
        ->where('subject_id', $planned->id)
        ->first();
    expect($activity?->properties->get('old_status'))->toBe('planned')
        ->and($activity?->properties->get('new_status'))->toBe('cancelled')
        ->and($activity?->properties->get('original_deleted_at'))->toBe($fixture['deletedAt'])
        ->and($activity?->properties->get('company_id'))->toBe($company->id);
});

test('null actor preserves historical updated_by and completed_by attribution', function () {
    $fixture = makeSoftDeletedPlannedTombstone();
    $assignmentUpdater = User::factory()->create();
    $phaseCompleter = User::factory()->create();
    $maintenanceActor = User::factory()->create();

    DB::table('crew_assignments')->where('id', $fixture['planned']->id)->update([
        'updated_by' => $assignmentUpdater->id,
    ]);
    DB::table('crew_assignment_phases')
        ->where('crew_assignment_id', $fixture['planned']->id)
        ->update([
            'completed_by' => $phaseCompleter->id,
        ]);

    $report = $this->retirer->apply([(int) $fixture['company']->id], null, null);

    expect($report->retired())->toBe(1);

    $retired = CrewAssignment::withTrashed()
        ->with(['phases' => fn ($q) => $q->withTrashed()])
        ->whereKey($fixture['planned']->id)
        ->firstOrFail();

    expect($retired->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($retired->updated_by)->toBe($assignmentUpdater->id)
        ->and($retired->phases->first()?->completed_by)->toBe($phaseCompleter->id)
        ->and($retired->deleted_at?->toDateTimeString())->toBe($fixture['deletedAt']);

    $activity = Activity::query()
        ->where('description', RetireSoftDeletedPlannedAssignments::ACTIVITY)
        ->where('subject_id', $fixture['planned']->id)
        ->first();
    expect($activity?->causer_id)->toBeNull();

    // With a real actor, attribution updates are allowed.
    $second = makeSoftDeletedPlannedTombstone();
    DB::table('crew_assignments')->where('id', $second['planned']->id)->update([
        'updated_by' => $assignmentUpdater->id,
    ]);
    DB::table('crew_assignment_phases')
        ->where('crew_assignment_id', $second['planned']->id)
        ->update([
            'completed_by' => $phaseCompleter->id,
        ]);

    $this->retirer->apply([(int) $second['company']->id], null, $maintenanceActor);

    $secondRetired = CrewAssignment::withTrashed()
        ->with(['phases' => fn ($q) => $q->withTrashed()])
        ->whereKey($second['planned']->id)
        ->firstOrFail();

    expect($secondRetired->updated_by)->toBe($maintenanceActor->id)
        ->and($secondRetired->phases->first()?->completed_by)->toBe($maintenanceActor->id);
});

test('timesheet segments including soft-deleted block retirement with zero writes', function () {
    $fixture = makeSoftDeletedPlannedTombstone();
    $planned = $fixture['planned'];
    $phase = CrewAssignmentPhase::withTrashed()->where('crew_assignment_id', $planned->id)->firstOrFail();

    $period = PayrollPeriod::factory()->for($fixture['company'])->create([
        'start_date' => '2027-05-01',
        'end_date' => '2027-05-31',
    ]);
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $fixture['company']->id,
        'employee_id' => $fixture['employee']->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
    ]);
    $segment = CrewTimesheetSegment::factory()->create([
        'company_id' => $fixture['company']->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2027-05-05',
        'to_date' => '2027-05-10',
        'days' => 6,
        'source' => CrewTimesheetSource::Manual,
        'crew_assignment_id' => $planned->id,
        'crew_assignment_phase_id' => $phase->id,
    ]);

    $activityCountBefore = Activity::query()
        ->where('description', RetireSoftDeletedPlannedAssignments::ACTIVITY)
        ->count();

    $report = $this->retirer->inspect([(int) $fixture['company']->id]);
    expect($report->blocked())->toBe(1)
        ->and($report->candidates[0]->blockers)->toContain('unexpected_timesheet_segments');

    $apply = $this->retirer->apply([(int) $fixture['company']->id]);
    expect($apply->abortedDueToBlockers)->toBeTrue();

    $fresh = CrewAssignment::withTrashed()
        ->with(['phases' => fn ($q) => $q->withTrashed()])
        ->whereKey($planned->id)
        ->firstOrFail();
    expect($fresh->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($fresh->deleted_at?->toDateTimeString())->toBe($fixture['deletedAt'])
        ->and($fresh->phases->first()?->status)->toBe(CrewPhaseStatus::Planned)
        ->and(Activity::query()
            ->where('description', RetireSoftDeletedPlannedAssignments::ACTIVITY)
            ->count())->toBe($activityCountBefore);

    // Soft-deleted segments still count as historical operational data.
    $segment->delete();
    expect(CrewTimesheetSegment::query()->whereKey($segment->id)->exists())->toBeFalse()
        ->and(CrewTimesheetSegment::withTrashed()->whereKey($segment->id)->exists())->toBeTrue();

    $afterSoftDelete = $this->retirer->inspect([(int) $fixture['company']->id]);
    expect($afterSoftDelete->candidates[0]->blockers)->toContain('unexpected_timesheet_segments');

    $applyAfterSoftDelete = $this->retirer->apply([(int) $fixture['company']->id]);
    expect($applyAfterSoftDelete->abortedDueToBlockers)->toBeTrue();

    $stillPlanned = CrewAssignment::withTrashed()->whereKey($planned->id)->firstOrFail();
    expect($stillPlanned->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($stillPlanned->deleted_at?->toDateTimeString())->toBe($fixture['deletedAt']);
});

test('operational actuals and sea service block retirement with zero writes', function () {
    $fixture = makeSoftDeletedPlannedTombstone();
    $planned = $fixture['planned'];
    $phase = CrewAssignmentPhase::withTrashed()->where('crew_assignment_id', $planned->id)->firstOrFail();

    $phase->update([
        'actual_start_at' => now(),
        'status' => CrewPhaseStatus::Active,
    ]);
    DB::table('crew_assignments')->where('id', $planned->id)->update([
        'started_at' => now(),
    ]);

    $report = $this->retirer->inspect([(int) $fixture['company']->id]);
    expect($report->blocked())->toBe(1)
        ->and($report->candidates[0]->blockers)->toContain('unexpected_started_at')
        ->and($report->candidates[0]->blockers)->toContain('unexpected_phase_status')
        ->and($report->candidates[0]->blockers)->toContain('unexpected_phase_actuals');

    $apply = $this->retirer->apply([(int) $fixture['company']->id]);
    expect($apply->abortedDueToBlockers)->toBeTrue();

    $fresh = CrewAssignment::withTrashed()->whereKey($planned->id)->firstOrFail();
    expect($fresh->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($fresh->deleted_at?->toDateTimeString())->toBe($fixture['deletedAt']);

    $fixture2 = makeSoftDeletedPlannedTombstone();
    $phase2 = CrewAssignmentPhase::withTrashed()
        ->where('crew_assignment_id', $fixture2['planned']->id)
        ->firstOrFail();
    EmployeeSeaService::factory()->forEmployee($fixture2['employee'])->create([
        'crew_assignment_phase_id' => $phase2->id,
        'start_date' => '2027-05-05',
        'end_date' => null,
    ]);

    $report2 = $this->retirer->inspect([(int) $fixture2['company']->id]);
    expect($report2->candidates[0]->blockers)->toContain('unexpected_sea_service');
});

test('p4 phase history blocks retirement', function () {
    $fixture = makeSoftDeletedPlannedTombstone();
    $phase = CrewAssignmentPhase::withTrashed()
        ->where('crew_assignment_id', $fixture['planned']->id)
        ->firstOrFail();
    $phase->update(['phase_code' => CrewPhaseCode::OnVessel]);

    $report = $this->retirer->inspect([(int) $fixture['company']->id]);
    expect($report->candidates[0]->blockers)->toContain('unexpected_p4_history');
});

test('active non-deleted Planned assignments are never handled', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'position' => $position] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Live Planned Vessel', $company);
    $livePlanned = $this->movements->createPlanned(
        (int) $company->id,
        (int) $employee->id,
        [
            'vessel_id' => $vessel->id,
            'position_id' => $position->id,
            'planned_arrival_at' => '2027-05-01',
            'planned_join_at' => '2027-05-05',
            'planned_signoff_at' => '2027-08-05',
        ],
        $user->id,
    );

    $tombstone = makeSoftDeletedPlannedTombstone();

    $report = $this->retirer->inspect([(int) $tombstone['company']->id]);
    expect($report->scanned())->toBe(1)
        ->and($report->candidates[0]->assignmentId)->toBe((int) $tombstone['planned']->id);

    $liveReport = $this->retirer->inspect([(int) $company->id]);
    expect($liveReport->scanned())->toBe(0);

    $liveApply = $this->retirer->apply([(int) $company->id]);
    expect($liveApply->retired())->toBe(0)
        ->and($livePlanned->fresh()->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($livePlanned->trashed())->toBeFalse();
});

test('company scope does not touch another company tombstone', function () {
    $companyA = makeSoftDeletedPlannedTombstone();
    $companyB = makeSoftDeletedPlannedTombstone();

    $report = $this->retirer->apply([(int) $companyA['company']->id], null, $companyA['user']);

    expect($report->retired())->toBe(1)
        ->and($report->candidates[0]->assignmentId)->toBe((int) $companyA['planned']->id);

    $a = CrewAssignment::withTrashed()->whereKey($companyA['planned']->id)->firstOrFail();
    $b = CrewAssignment::withTrashed()->whereKey($companyB['planned']->id)->firstOrFail();

    expect($a->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($b->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($b->deleted_at?->toDateTimeString())->toBe($companyB['deletedAt']);
});

test('assignment flag targets one tombstone and apply is idempotent', function () {
    $first = makeSoftDeletedPlannedTombstone();
    $company = $first['company'];
    $user = $first['user'];

    $secondEmployee = Employee::factory()->forCompany($company)->create([
        'position_id' => $first['position']->id,
        'status' => 'active',
    ]);
    $secondPlanned = $this->movements->createPlanned(
        (int) $company->id,
        (int) $secondEmployee->id,
        [
            'vessel_id' => $first['vessel']->id,
            'position_id' => $first['position']->id,
            'planned_arrival_at' => '2027-06-01',
            'planned_join_at' => '2027-06-05',
            'planned_signoff_at' => '2027-09-05',
        ],
        $user->id,
    );
    $secondPlanned->delete();
    DB::table('crew_assignments')->where('id', $secondPlanned->id)->update([
        'deleted_at' => '2026-09-30 10:00:00',
    ]);

    $report = $this->retirer->apply(
        [(int) $company->id],
        (int) $first['planned']->id,
        $user,
    );

    expect($report->scanned())->toBe(1)
        ->and($report->retired())->toBe(1);

    $firstFresh = CrewAssignment::withTrashed()->whereKey($first['planned']->id)->firstOrFail();
    $secondFresh = CrewAssignment::withTrashed()->whereKey($secondPlanned->id)->firstOrFail();
    expect($firstFresh->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($secondFresh->status)->toBe(CrewAssignmentStatus::Planned);

    $secondPass = $this->retirer->apply(
        [(int) $company->id],
        (int) $first['planned']->id,
        $user,
    );

    expect($secondPass->scanned())->toBe(0)
        ->and($secondPass->retired())->toBe(0)
        ->and($secondPass->remainingSoftDeletedPlannedCount)->toBe(0);

    $this->artisan('crew-planning:retire-soft-deleted-planned', [
        '--company' => $company->id,
        '--assignment' => $first['planned']->id,
        '--apply' => true,
    ])->assertSuccessful();
});

test('does not broaden normal Phase 4 migrator to soft-deleted rows', function () {
    $tombstone = makeSoftDeletedPlannedTombstone();

    $this->artisan('crew-planning:migrate-legacy-planned', [
        '--company' => $tombstone['company']->id,
    ])->assertSuccessful();

    $fresh = CrewAssignment::withTrashed()->whereKey($tombstone['planned']->id)->firstOrFail();
    expect($fresh->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($fresh->trashed())->toBeTrue()
        ->and(CrewPlanningAssignment::query()->where('company_id', $tombstone['company']->id)->count())->toBe(0);
});
