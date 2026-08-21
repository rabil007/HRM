<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\Activity\ActivityChangePresenter;
use App\Support\Activity\RecentActivityQuery;
use Carbon\Carbon;
use Spatie\Activitylog\Models\Activity;

function makeActivityPresentationFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'APL',
        'name' => 'Activity Present Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'APL',
        'name' => 'Activity Present Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Activity Present Co',
        'slug' => 'activity-present-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user->forceFill(['company_id' => $company->id])->save();

    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Ali Hassan',
        'employee_no' => 'EMP-9001',
        'status' => 'active',
    ]);

    return compact('user', 'company', 'employee', 'country');
}

test('recent activity resolves foreign key ids to human readable labels', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeActivityPresentationFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['audit.view', 'trainings.view']);

    $course = Course::query()->create([
        'name' => 'STCW Basic Safety',
        'is_active' => true,
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'course_id' => $course->id,
        'institute_center' => 'Marine Academy',
    ]);

    $items = RecentActivityQuery::for(
        $user,
        $company->id,
        EmployeeTraining::class,
        $training->id,
    );

    expect($items)->not->toBeEmpty();

    $created = collect($items)->firstWhere('event', 'created');

    expect($created)->not->toBeNull()
        ->and(data_get($created, 'new_values.course_id'))->toBe('STCW Basic Safety')
        ->and(data_get($created, 'new_values.employee_id'))->toBe('Ali Hassan (EMP-9001)');
});

test('activity change presenter resolves old and new course names on update', function () {
    ['company' => $company, 'employee' => $employee] = makeActivityPresentationFixtures();

    $oldCourse = Course::query()->create([
        'name' => 'Basic Firefighting',
        'is_active' => true,
    ]);
    $newCourse = Course::query()->create([
        'name' => 'Advanced Firefighting',
        'is_active' => true,
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'course_id' => $oldCourse->id,
    ]);

    $training->update(['course_id' => $newCourse->id]);

    $log = Activity::query()
        ->where('subject_type', EmployeeTraining::class)
        ->where('subject_id', $training->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $activity): array => ActivityChangePresenter::toRecentActivityArray($activity))
        ->first();

    expect(data_get($presented, 'old_values.course_id'))->toBe('Basic Firefighting')
        ->and(data_get($presented, 'new_values.course_id'))->toBe('Advanced Firefighting');
});

test('activity logs page shows resolved labels instead of raw ids', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeActivityPresentationFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['audit.view']);

    $course = Course::query()->create([
        'name' => 'Medical First Aid',
        'is_active' => true,
    ]);

    EmployeeTraining::factory()->forEmployee($employee)->create([
        'course_id' => $course->id,
    ]);

    $this->get(route('organization.activity-logs', [
        'date_from' => now()->toDateString(),
        'date_to' => now()->toDateString(),
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/activity-logs')
            ->has('logs', fn ($logs) => $logs
                ->where('0.new_values.course_id', 'Medical First Aid')
                ->etc()
            )
        );
});

test('crew assignment recent activity resolves phase vessel and assignment ids to labels', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'audit.view',
        'crew_operations.assignments.view',
    ]);

    $sourceVessel = makeCrewMovementVessel('Source Vessel');
    $destinationVessel = makeCrewMovementVessel('Destination Vessel');

    $previous = CrewAssignment::factory()->forEmployee($employee)->create([
        'rank_id' => $rank->id,
        'vessel_id' => $sourceVessel->id,
        'assignment_no' => 'CA-TEST-PREV',
        'status' => 'completed',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'rank_id' => $rank->id,
        'vessel_id' => $destinationVessel->id,
        'assignment_no' => 'CA-TEST-DEST',
        'status' => 'active',
        'previous_assignment_id' => $previous->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 1,
        'actual_start_at' => now(),
        'started_by' => $user->id,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'crew_vessel_transferred',
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'source_assignment_id' => $previous->id,
            'destination_assignment_id' => $assignment->id,
            'source_vessel_id' => $sourceVessel->id,
            'destination_vessel_id' => $destinationVessel->id,
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->tap(function ($activity) use ($company): void {
            $activity->company_id = $company->id;
        })
        ->log('Crew vessel transferred');

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/crew/show')
            ->has('recent_activity')
            ->where('recent_activity.0.description', 'Crew vessel transferred')
            ->where('recent_activity.0.new_values.employee_id', $employee->name.' ('.$employee->employee_no.')')
            ->where('recent_activity.0.new_values.source_assignment_id', 'CA-TEST-PREV')
            ->where('recent_activity.0.new_values.destination_assignment_id', 'CA-TEST-DEST')
            ->where('recent_activity.0.new_values.source_vessel_id', $sourceVessel->name)
            ->where('recent_activity.0.new_values.destination_vessel_id', $destinationVessel->name)
            ->where('recent_activity.1.new_values.current_phase_id', 'P4 On Vessel')
            ->where('recent_activity.2.new_values.previous_assignment_id', 'CA-TEST-PREV')
            ->where('recent_activity.2.new_values.vessel_id', $destinationVessel->name)
        );
});

// ─── Crew Correction Activity Presentation ───────────────────────────────────

/**
 * Build a raw correction snapshot entry as stored by CrewMovementCorrectionValueSnapshot.
 *
 * @param  string  $display  Pre-formatted display value (Y-m-d H:i for datetimes)
 * @param  mixed  $value  Serialised raw value
 */
function correctionSnapshotEntry(string $display, mixed $value = null): array
{
    return ['display' => $display, 'value' => $value ?? $display];
}

function makeOnVesselCorrectionFixtures(): array
{
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('MV Horizon', $company);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'assignment_no' => 'CA-CORR-001',
        'status' => 'active',
    ]);

    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 1,
        'actual_start_at' => Carbon::parse('2026-06-07 11:17:00', 'Asia/Dubai'),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    return compact('user', 'company', 'employee', 'assignment', 'phase', 'rank', 'vessel');
}

test('correction_requested activity shows phase label and field changes in human readable form', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOnVesselCorrectionFixtures();

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'pending',
        'original_values' => [
            'actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17'),
        ],
        'proposed_values' => [
            'actual_start_at' => correctionSnapshotEntry('2026-06-21 11:17'),
        ],
        'reason' => 'ADS',
        'requested_by' => $user->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_requested',
            'correction_id' => $correction->id,
            'phase_id' => $phase->id,
            'proposed_values' => $correction->proposed_values,
            'reason' => 'ADS',
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction requested');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_requested')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    expect($presented)->not->toBeNull()
        ->and(data_get($presented, 'new_values.Phase'))->toBe('P4 · On Vessel')
        ->and(data_get($presented, 'new_values.Actual Start'))->toContain('→')
        ->and(data_get($presented, 'new_values.Actual Start'))->toContain('07-06-2026')
        ->and(data_get($presented, 'new_values.Actual Start'))->toContain('21-06-2026')
        ->and(data_get($presented, 'new_values.Reason'))->toBe('ADS')
        ->and(data_get($presented, 'old_values'))->toBeNull();
});

test('correction_requested activity does not expose correction_id phase_id or raw json', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOnVesselCorrectionFixtures();

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'pending',
        'original_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17')],
        'proposed_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-21 11:17')],
        'reason' => 'Test',
        'requested_by' => $user->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_requested',
            'correction_id' => $correction->id,
            'phase_id' => $phase->id,
            'proposed_values' => $correction->proposed_values,
            'reason' => 'Test',
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction requested');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_requested')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    $newValues = data_get($presented, 'new_values');

    expect($newValues)->toBeArray()
        ->and(array_key_exists('correction_id', $newValues))->toBeFalse()
        ->and(array_key_exists('phase_id', $newValues))->toBeFalse()
        ->and(array_key_exists('proposed_values', $newValues))->toBeFalse()
        ->and(array_key_exists('applied_values', $newValues))->toBeFalse();

    // No raw JSON strings
    foreach ($newValues as $val) {
        expect($val)->not->toStartWith('{')->not->toStartWith('[');
    }
});

test('correction_approved activity shows applied field changes and decision note', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOnVesselCorrectionFixtures();

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'approved',
        'original_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17')],
        'proposed_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-21 11:17')],
        'applied_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-21 11:17')],
        'decision_notes' => 'DAFCSDF',
        'requested_by' => $user->id,
        'decided_by' => $user->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_approved',
            'correction_id' => $correction->id,
            'phase_id' => $phase->id,
            'applied_values' => $correction->applied_values,
            'decision_notes' => 'DAFCSDF',
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction approved');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_approved')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    expect(data_get($presented, 'new_values.Phase'))->toBe('P4 · On Vessel')
        ->and(data_get($presented, 'new_values.Actual Start'))->toContain('07-06-2026')
        ->and(data_get($presented, 'new_values.Actual Start'))->toContain('21-06-2026')
        ->and(data_get($presented, 'new_values.Decision Note'))->toBe('DAFCSDF')
        ->and(data_get($presented, 'old_values'))->toBeNull();
});

test('correction_rejected activity shows phase label status and decision note', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOnVesselCorrectionFixtures();

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'rejected',
        'original_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17')],
        'proposed_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-21 11:17')],
        'decision_notes' => 'Not valid',
        'requested_by' => $user->id,
        'decided_by' => $user->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_rejected',
            'correction_id' => $correction->id,
            'decision_notes' => 'Not valid',
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction rejected');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_rejected')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    expect(data_get($presented, 'new_values.Phase'))->toBe('P4 · On Vessel')
        ->and(data_get($presented, 'new_values.Status'))->toBe('Rejected')
        ->and(data_get($presented, 'new_values.Decision Note'))->toBe('Not valid')
        ->and(data_get($presented, 'old_values'))->toBeNull();
});

test('correction_cancelled activity shows phase label and cancelled status', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOnVesselCorrectionFixtures();

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'cancelled',
        'original_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17')],
        'proposed_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-21 11:17')],
        'requested_by' => $user->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_cancelled',
            'correction_id' => $correction->id,
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction cancelled');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_cancelled')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    expect(data_get($presented, 'new_values.Phase'))->toBe('P4 · On Vessel')
        ->and(data_get($presented, 'new_values.Status'))->toBe('Cancelled')
        ->and(data_get($presented, 'old_values'))->toBeNull();
});

test('correction activity with actual_end_at shows end date change', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOnVesselCorrectionFixtures();

    $phase->update(['status' => CrewPhaseStatus::Completed, 'actual_end_at' => Carbon::parse('2026-06-30 08:00:00', 'Asia/Dubai')]);

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'pending',
        'original_values' => [
            'actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17'),
            'actual_end_at' => correctionSnapshotEntry('2026-06-30 08:00'),
        ],
        'proposed_values' => [
            'actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17'),
            'actual_end_at' => correctionSnapshotEntry('2026-07-05 08:00'),
        ],
        'reason' => 'Tour extended',
        'requested_by' => $user->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_requested',
            'correction_id' => $correction->id,
            'phase_id' => $phase->id,
            'proposed_values' => $correction->proposed_values,
            'reason' => 'Tour extended',
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction requested');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_requested')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    expect(data_get($presented, 'new_values.Actual End'))->toContain('30-06-2026')
        ->and(data_get($presented, 'new_values.Actual End'))->toContain('05-07-2026');
});

test('correction activity with remarks field shows remarks change', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOnVesselCorrectionFixtures();

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'pending',
        'original_values' => [
            'actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17'),
            'remarks' => correctionSnapshotEntry('Old remark'),
        ],
        'proposed_values' => [
            'actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17'),
            'remarks' => correctionSnapshotEntry('Updated remark'),
        ],
        'reason' => 'Remark fix',
        'requested_by' => $user->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_requested',
            'correction_id' => $correction->id,
            'phase_id' => $phase->id,
            'proposed_values' => $correction->proposed_values,
            'reason' => 'Remark fix',
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction requested');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_requested')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    expect(data_get($presented, 'new_values.Remarks'))->toContain('Old remark')
        ->and(data_get($presented, 'new_values.Remarks'))->toContain('Updated remark');
});

test('correction activity with vessel_id change shows vessel name not raw id', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase, 'vessel' => $vessel] = makeOnVesselCorrectionFixtures();

    $newVessel = makeCrewMovementVessel('MV Pacific', $company);

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'pending',
        'original_values' => [
            'actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17'),
            'vessel_id' => correctionSnapshotEntry($vessel->name, $vessel->id),
        ],
        'proposed_values' => [
            'actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17'),
            'vessel_id' => correctionSnapshotEntry($newVessel->name, $newVessel->id),
        ],
        'reason' => 'Vessel reassigned',
        'requested_by' => $user->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_requested',
            'correction_id' => $correction->id,
            'phase_id' => $phase->id,
            'proposed_values' => $correction->proposed_values,
            'reason' => 'Vessel reassigned',
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction requested');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_requested')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    $vesselChange = data_get($presented, 'new_values.Vessel');

    expect($vesselChange)->toBe("{$vessel->name} → {$newVessel->name}");
});

test('correction activity without decision note omits decision note key', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOnVesselCorrectionFixtures();

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'rejected',
        'original_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17')],
        'proposed_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-21 11:17')],
        'decision_notes' => null,
        'requested_by' => $user->id,
        'decided_by' => $user->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_rejected',
            'correction_id' => $correction->id,
            'decision_notes' => null,
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction rejected');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_rejected')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    $newValues = data_get($presented, 'new_values');

    expect($newValues)->toBeArray()
        ->and(array_key_exists('Decision Note', $newValues))->toBeFalse();
});

test('correction activity falls back gracefully when correction id cannot be resolved', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment] = makeOnVesselCorrectionFixtures();

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_requested',
            'correction_id' => 999999999,
            'phase_id' => 999999999,
            'reason' => 'Test',
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction requested');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_requested')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    // Should not throw and should return a Status fallback key
    expect(data_get($presented, 'new_values.Status'))->toBe('Correction requested')
        ->and(data_get($presented, 'old_values'))->toBeNull();
});

test('correction activity cannot resolve cross company correction data', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOnVesselCorrectionFixtures();

    // Second company — correction ID belongs here, not to $company
    ['company' => $otherCompany, 'assignment' => $otherAssignment, 'phase' => $otherPhase, 'user' => $otherUser] = makeOnVesselCorrectionFixtures();

    $otherCorrection = CrewMovementCorrection::factory()->create([
        'company_id' => $otherCompany->id,
        'crew_assignment_id' => $otherAssignment->id,
        'crew_assignment_phase_id' => $otherPhase->id,
        'status' => 'pending',
        'original_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17')],
        'proposed_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-21 11:17')],
        'reason' => 'Cross company test',
        'requested_by' => $otherUser->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_requested',
            'correction_id' => $otherCorrection->id, // correct ID, wrong company
            'phase_id' => $phase->id,
            'reason' => 'Spoofed',
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction requested');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_requested')
        ->latest('id')
        ->first();

    // Presented with $company->id — should NOT resolve the other company's correction
    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    expect(data_get($presented, 'new_values.Status'))->toBe('Correction requested')
        ->and(data_get($presented, 'new_values'))->not->toHaveKey('Phase')
        ->and(data_get($presented, 'new_values'))->not->toHaveKey('Reason');
});

test('standard created and updated activities are unaffected by correction presenter', function () {
    ['company' => $company, 'employee' => $employee] = makeActivityPresentationFixtures();

    $course = Course::query()->create(['name' => 'Fire Safety', 'is_active' => true]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'course_id' => $course->id,
    ]);

    $training->update(['institute_center' => 'Updated Institute']);

    $logs = Activity::query()
        ->where('subject_type', EmployeeTraining::class)
        ->where('subject_id', $training->id)
        ->latest('id')
        ->get();

    $presented = ActivityChangePresenter::presentLogs($logs, $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->values()
        ->all();

    // Standard events must still carry old_values and new_values from attribute changes
    $updated = collect($presented)->firstWhere('event', 'updated');
    $created = collect($presented)->firstWhere('event', 'created');

    expect($updated)->not->toBeNull()
        ->and($created)->not->toBeNull()
        ->and(data_get($updated, 'new_values.institute_center'))->toBe('Updated Institute')
        ->and(data_get($created, 'new_values.course_id'))->toBe('Fire Safety');
});

test('correction activity dates are reformatted to d-m-Y H:i style', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOnVesselCorrectionFixtures();

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'pending',
        'original_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-07 11:17')],
        'proposed_values' => ['actual_start_at' => correctionSnapshotEntry('2026-06-21 09:30')],
        'reason' => 'Date format test',
        'requested_by' => $user->id,
    ]);

    activity()
        ->performedOn($assignment)
        ->causedBy($user)
        ->withProperties([
            'event' => 'correction_requested',
            'correction_id' => $correction->id,
            'phase_id' => $phase->id,
            'proposed_values' => $correction->proposed_values,
            'reason' => 'Date format test',
        ])
        ->tap(fn ($a) => $a->company_id = $company->id)
        ->log('Crew movement correction requested');

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'correction_requested')
        ->latest('id')
        ->first();

    $presented = ActivityChangePresenter::presentLogs(collect([$log]), $company->id)
        ->map(fn (Activity $a): array => ActivityChangePresenter::toRecentActivityArray($a))
        ->first();

    $actualStart = data_get($presented, 'new_values.Actual Start');

    // Must use d-m-Y H:i format (not Y-m-d)
    expect($actualStart)
        ->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2} → \d{2}-\d{2}-\d{4} \d{2}:\d{2}$/')
        ->toContain('07-06-2026 11:17')
        ->toContain('21-06-2026 09:30');
});
