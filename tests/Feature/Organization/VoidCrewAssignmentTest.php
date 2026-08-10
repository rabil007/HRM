<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewOperationalAlert;
use App\Models\CrewPlanningAssignment;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\PayrollPeriod;
use App\Models\Rank;
use App\Models\User;
use App\Models\VesselManning;
use App\Support\CrewMovements\Actions\VoidCrewAssignment;
use App\Support\CrewMovements\CrewAssignmentVoidGuard;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use App\Support\CrewOperations\ReconcileCrewOperationalAlerts;
use App\Support\CrewPlanning\SyncPlanningAssignmentFromCrewAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank}
 */
function makeVoidAssignmentFixtures(array $extraPermissions = []): array
{
    $fixtures = makeCrewAssignmentFixtures();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], array_values(array_unique(array_merge([
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
        'crew_operations.assignments.void',
        'audit.view',
    ], $extraPermissions))));

    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

function voidAssignmentViaHttp(User $user, CrewAssignment $assignment, string $reason = 'Entered by mistake'): mixed
{
    return actingAs($user)
        ->withSession(['current_company_id' => $user->current_company_id])
        ->post(route('organization.crew-assignments.void', $assignment), [
            'void_reason' => $reason,
        ]);
}

function advanceToPhase(
    CrewMovementService $service,
    int $companyId,
    int $assignmentId,
    int $userId,
    CrewPhaseCode $target,
    ?int $vesselId = null,
    ?int $rankId = null,
): void {
    $service->perform($companyId, $assignmentId, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $userId);

    if ($target === CrewPhaseCode::TravelIn) {
        return;
    }

    $service->perform($companyId, $assignmentId, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p2a',
    ], $userId);

    if ($target === CrewPhaseCode::JoinStandby) {
        return;
    }

    if ($target === CrewPhaseCode::Training) {
        $service->perform($companyId, $assignmentId, CrewMovementAction::SendToTraining, [
            'occurred_at' => '2026-01-03 08:00:00',
            'provider' => 'Provider',
            'course' => 'Course',
        ], $userId);

        return;
    }

    $service->perform($companyId, $assignmentId, CrewMovementAction::MarkReady, [
        'occurred_at' => '2026-01-04 08:00:00',
    ], $userId);

    if ($target === CrewPhaseCode::ReadyToJoin) {
        return;
    }

    $service->perform($companyId, $assignmentId, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-05 08:00:00',
        'vessel_id' => $vesselId,
        'rank_id' => $rankId,
    ], $userId);

    if ($target === CrewPhaseCode::OnVessel) {
        return;
    }

    $service->perform($companyId, $assignmentId, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-03-01 08:00:00',
        'next_phase' => $target === CrewPhaseCode::HomeRedeploy ? 'p6' : 'p5',
    ], $userId);
}

test('show page exposes can.void for authorized users', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/show')
            ->where('can.void', true)
            ->where('can.cancel', true));
});

test('safe draft assignment can be voided', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    voidAssignmentViaHttp($user, $assignment)
        ->assertRedirect(route('organization.crew-assignments.index'))
        ->assertSessionHas('success');

    $voided = CrewAssignment::withTrashed()->findOrFail($assignment->id);

    expect($voided->trashed())->toBeTrue()
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->voided_by)->toBe($user->id)
        ->and($voided->void_reason)->toBe('Entered by mistake')
        ->and(CrewAssignment::query()->whereKey($assignment->id)->exists())->toBeFalse();
});

test('safe p1 assignment can be voided', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);
    advanceToPhase($service, $company->id, $assignment->id, $user->id, CrewPhaseCode::TravelIn);

    voidAssignmentViaHttp($user, $assignment->fresh())->assertRedirect();

    expect(CrewAssignment::withTrashed()->findOrFail($assignment->id)->trashed())->toBeTrue();
});

test('safe p2 and p3 assignments can be voided', function (CrewPhaseCode $phase) {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);
    advanceToPhase($service, $company->id, $assignment->id, $user->id, $phase);

    voidAssignmentViaHttp($user, $assignment->fresh(), 'Wrong phase progression')->assertRedirect();

    $voided = CrewAssignment::withTrashed()->findOrFail($assignment->id);
    expect($voided->trashed())->toBeTrue()
        ->and($voided->void_reason)->toBe('Wrong phase progression');
})->with([
    CrewPhaseCode::JoinStandby,
    CrewPhaseCode::Training,
    CrewPhaseCode::ReadyToJoin,
]);

test('safe p4 can be voided when no protected downstream history exists', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Void P4 Vessel');
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    advanceToPhase($service, $company->id, $assignment->id, $user->id, CrewPhaseCode::OnVessel, $vessel->id, $rank->id);

    expect($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);

    voidAssignmentViaHttp($user, $assignment->fresh())->assertRedirect();

    expect(CrewAssignment::withTrashed()->findOrFail($assignment->id)->trashed())->toBeTrue()
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

test('safe p5 and p6 can be voided when sea service sync is disabled', function (string $nextPhase) {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    CrewOperationsSettings::saveSettings($company->id, [], 30, false, [
        'notifications_enabled' => false,
    ]);

    $vessel = makeCrewMovementVessel('Void Demob Vessel');
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    advanceToPhase(
        $service,
        $company->id,
        $assignment->id,
        $user->id,
        $nextPhase === 'p6' ? CrewPhaseCode::HomeRedeploy : CrewPhaseCode::DemobStandby,
        $vessel->id,
        $rank->id,
    );

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->exists())->toBeFalse();

    voidAssignmentViaHttp($user, $assignment->fresh())->assertRedirect();

    expect(CrewAssignment::withTrashed()->findOrFail($assignment->id)->trashed())->toBeTrue();
})->with(['p5', 'p6']);

test('void reason is required', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.void', $assignment), [
            'void_reason' => '   ',
        ])
        ->assertSessionHasErrors('void_reason');

    expect(CrewAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});

test('unauthorized user gets 403 when voiding', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.cancel',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    voidAssignmentViaHttp($user, $assignment)->assertForbidden();
});

test('cross-company assignment void is blocked', function () {
    ['user' => $user] = makeVoidAssignmentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();

    $foreign = app(CrewMovementService::class)->createDraft($otherCompany->id, $otherEmployee->id, [
        'rank_id' => $otherRank->id,
    ]);

    voidAssignmentViaHttp($user, $foreign)->assertNotFound();
});

test('voided assignment disappears from current crew', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Current Crew Void Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    expect(CurrentCrewQuery::paginate($company->id)->total())->toBe(1);

    app(VoidCrewAssignment::class)->handle($company->id, $assignment->id, $user, 'Duplicate onboard');

    expect(CurrentCrewQuery::paginate($company->id)->total())->toBe(0);
});

test('voided assignment no longer contributes to projected manning', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Projected Void Vessel');
    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_signoff_at' => '2026-09-01 00:00:00',
    ]);

    $before = (new CrewProjectedManningQuery)->forCompany(
        $company->id,
        '2026-08-01',
        '2026-08-31',
        $vessel->id,
        $rank->id,
    );
    expect($before['items'][0]['actual_onboard_at_start'])->toBe(1);

    app(VoidCrewAssignment::class)->handle($company->id, $assignment->id, $user, 'Wrong vessel join');

    $after = (new CrewProjectedManningQuery)->forCompany(
        $company->id,
        '2026-08-01',
        '2026-08-31',
        $vessel->id,
        $rank->id,
    );
    expect($after['items'][0]['actual_onboard_at_start'])->toBe(0);
});

test('voided assignment no longer contributes to operational alerts', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Alert Void Vessel');
    CrewOperationsSettings::saveSettings($company->id, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [$user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => false,
        'alert_relief_not_ready' => false,
        'alert_current_manning_gap' => false,
        'alert_projected_manning_gap' => false,
    ]);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_signoff_at' => '2026-08-01 00:00:00',
    ]);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($company->id);

    expect(CrewOperationalAlert::query()
        ->where('company_id', $company->id)
        ->where('status', 'active')
        ->where('type', 'signoff_overdue')
        ->count())->toBe(1);

    app(VoidCrewAssignment::class)->handle($company->id, $assignment->id, $user, 'Erroneous onboard');

    app(ReconcileCrewOperationalAlerts::class)->forCompany($company->id);

    expect(CrewOperationalAlert::query()
        ->where('company_id', $company->id)
        ->where('status', 'active')
        ->where('type', 'signoff_overdue')
        ->count())->toBe(0);

    CarbonImmutable::setTestNow();
});

test('void soft-deletes derived planning sync row', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Void Vessel');
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-08-01 00:00:00',
        'planned_signoff_at' => '2026-11-01 00:00:00',
    ], $user->id);

    $planning = app(SyncPlanningAssignmentFromCrewAssignment::class)->sync($assignment);
    expect($planning)->not->toBeNull()
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->exists())->toBeTrue();

    voidAssignmentViaHttp($user, $assignment)->assertRedirect();

    expect(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->exists())->toBeFalse()
        ->and(CrewPlanningAssignment::withTrashed()->where('crew_assignment_id', $assignment->id)->exists())->toBeTrue();
});

test('linked transfer child blocks void', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $sourceVessel = makeCrewMovementVessel('Transfer Source Void');
    $destinationVessel = makeCrewMovementVessel('Transfer Dest Void');
    $source = makeActiveOnVesselAssignment($company, $employee, $rank, $sourceVessel);

    app(CrewMovementService::class)->perform($company->id, $source->id, CrewMovementAction::TransferVessel, [
        'occurred_at' => '2026-06-01 08:00:00',
        'vessel_id' => $destinationVessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $child = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('previous_assignment_id', $source->id)
        ->firstOrFail();

    expect(fn () => app(VoidCrewAssignment::class)->handle($company->id, $source->id, $user, 'Attempt void source'))
        ->toThrow(ValidationException::class);

    $codes = collect(app(CrewAssignmentVoidGuard::class)->blockers(
        CrewAssignment::withTrashed()->findOrFail($source->id),
        $company->id,
    ))->pluck('code');

    expect($codes)->toContain('linked_assignment_exists')
        ->and($child->fresh()->trashed())->toBeFalse();
});

test('employee sea service blocks void', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Sea Service Void Vessel');
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    advanceToPhase($service, $company->id, $assignment->id, $user->id, CrewPhaseCode::DemobStandby, $vessel->id, $rank->id);

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.void', $assignment), [
            'void_reason' => 'Should be blocked',
        ])
        ->assertSessionHasErrors('void');

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->exists())->toBeTrue()
        ->and(CrewAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();

    $codes = collect(app(CrewAssignmentVoidGuard::class)->blockers($assignment->fresh(), $company->id))->pluck('code');
    expect($codes)->toContain('sea_service_exists');
});

test('applied payroll dependency blocks void', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.void',
        'crew_operations.assignments.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    ['preparation' => $preparation] = prepareApprovedTimeline($fixtures);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Applied,
        'applied_by' => $fixtures['user']->id,
        'applied_at' => now(),
    ]);

    expect(fn () => app(VoidCrewAssignment::class)->handle(
        $fixtures['company']->id,
        $fixtures['assignment']->id,
        $fixtures['user'],
        'Payroll applied',
    ))->toThrow(ValidationException::class);

    $codes = collect(app(CrewAssignmentVoidGuard::class)->blockers(
        $fixtures['assignment']->fresh(),
        $fixtures['company']->id,
    ))->pluck('code');
    expect($codes)->toContain('payroll_applied');
});

test('approved payroll dependency blocks void', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.void',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    prepareApprovedTimeline($fixtures);

    expect(fn () => app(VoidCrewAssignment::class)->handle(
        $fixtures['company']->id,
        $fixtures['assignment']->id,
        $fixtures['user'],
        'Payroll approved',
    ))->toThrow(ValidationException::class);

    $codes = collect(app(CrewAssignmentVoidGuard::class)->blockers(
        $fixtures['assignment']->fresh(),
        $fixtures['company']->id,
    ))->pluck('code');
    expect($codes)->toContain('payroll_protected');
});

test('paid payroll period segment blocks void', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Paid,
        'payroll_category' => 'crew',
    ]);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::CrewOperations,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'crew_assignment_id' => $assignment->id,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-05',
        'days' => 5,
        'source' => CrewTimesheetSource::CrewOperations,
    ]);

    $codes = collect(app(CrewAssignmentVoidGuard::class)->blockers($assignment->fresh(), $company->id))->pluck('code');
    expect($codes)->toContain('payroll_protected')
        ->and($codes)->toContain('protected_dependency_exists');
});

test('timesheet segment dependency blocks void', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => 'crew',
    ]);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::CrewOperations,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'crew_assignment_id' => $assignment->id,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-05',
        'days' => 5,
        'source' => CrewTimesheetSource::CrewOperations,
    ]);

    $codes = collect(app(CrewAssignmentVoidGuard::class)->blockers($assignment->fresh(), $company->id))->pluck('code');
    expect($codes)->toContain('protected_dependency_exists');
});

test('normal cancel behavior remains unchanged', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-01-01 08:00:00',
            'reason' => 'Client cancelled',
        ])
        ->assertRedirect();

    $cancelled = $assignment->fresh();
    expect($cancelled->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($cancelled->trashed())->toBeFalse()
        ->and($cancelled->voided_at)->toBeNull();
});

test('p4 still does not expose normal cancel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $vessel = makeCrewMovementVessel('No Cancel P4 Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.void', true)
            ->where('assignment.available_actions', fn ($actions) => ! in_array(
                'cancel_assignment',
                collect($actions)->all(),
                true,
            )));

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-01-10 08:00:00',
            'reason' => 'Should fail on vessel',
        ])
        ->assertSessionHasErrors('error');
});

test('void creates company-aware activity audit', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    app(VoidCrewAssignment::class)->handle($company->id, $assignment->id, $user, 'Duplicate assignment');

    $activity = Activity::query()
        ->where('description', 'Crew assignment voided as erroneous')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and((int) $activity->company_id)->toBe($company->id)
        ->and($activity->properties['void_reason'])->toBe('Duplicate assignment')
        ->and($activity->properties['event'])->toBe('crew_assignment_voided');
});

test('already voided assignment cannot be voided again', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    app(VoidCrewAssignment::class)->handle($company->id, $assignment->id, $user, 'First void');

    expect(fn () => app(VoidCrewAssignment::class)->handle($company->id, $assignment->id, $user, 'Second void'))
        ->toThrow(ValidationException::class);

    voidAssignmentViaHttp($user, $assignment)->assertNotFound();
});

test('concurrent void attempts are safe under lock', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $action = app(VoidCrewAssignment::class);
    $action->handle($company->id, $assignment->id, $user, 'First concurrent');

    expect(fn () => $action->handle($company->id, $assignment->id, $user, 'Second concurrent'))
        ->toThrow(ValidationException::class);

    expect(CrewAssignment::withTrashed()->whereKey($assignment->id)->count())->toBe(1)
        ->and(CrewAssignment::withTrashed()->findOrFail($assignment->id)->void_reason)->toBe('First concurrent');
});

test('phase history is retained under soft-deleted assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Retain Phases Vessel');
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    advanceToPhase($service, $company->id, $assignment->id, $user->id, CrewPhaseCode::OnVessel, $vessel->id, $rank->id);

    $phaseCount = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->count();
    expect($phaseCount)->toBeGreaterThan(0);

    app(VoidCrewAssignment::class)->handle($company->id, $assignment->id, $user, 'Keep phases');

    expect(CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->count())->toBe($phaseCount);
});

test('tenant isolation: void guard ignores foreign company sea service rows', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeVoidAssignmentFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Tenant Void Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;

    EmployeeSeaService::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $employee->id,
        'crew_assignment_phase_id' => $phase->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2026-01-03',
        'end_date' => '2026-02-01',
        'total_months' => 0,
        'total_days' => 30,
    ]);

    $blockers = app(CrewAssignmentVoidGuard::class)->blockers($assignment->fresh(), $company->id);
    expect(collect($blockers)->pluck('code'))->not->toContain('sea_service_exists');

    app(VoidCrewAssignment::class)->handle($company->id, $assignment->id, $user, 'Foreign sea service ignored');

    expect(CrewAssignment::withTrashed()->findOrFail($assignment->id)->trashed())->toBeTrue();
});
