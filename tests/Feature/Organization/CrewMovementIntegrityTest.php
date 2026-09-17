<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Exceptions\CrewMovementException;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00', 'Asia/Dubai'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank}
 */
function makeCrewMovementIntegrityFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

test('cancelling draft assignment before mobilisation marks unstarted phase as cancelled', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $phaseId = $assignment->current_phase_id;

    $assignment = $service->perform($company->id, $assignment->id, CrewMovementAction::CancelAssignment, [
        'reason' => 'Planning withdrawn',
        'occurred_at' => '2026-09-17 11:00:00',
    ], $user->id);

    $phase = CrewAssignmentPhase::query()->find($phaseId);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($phase?->status)->toBe(CrewPhaseStatus::Cancelled)
        ->and($phase?->actual_start_at)->toBeNull();
});

test('cancelling after actual standby preserves elapsed phase as completed history', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $service = app(CrewMovementService::class);
    $vessel = makeCrewMovementVessel('Cancel Standby Vessel', $company);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-10 08:00:00',
    ], $user->id);
    $assignment = $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-10 12:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
    ], $user->id);

    $standbyPhaseId = $assignment->current_phase_id;

    $assignment = $service->perform($company->id, $id, CrewMovementAction::CancelAssignment, [
        'reason' => 'Client cancelled mobilisation',
        'occurred_at' => '2026-09-13 15:00:00',
    ], $user->id);

    $standbyPhase = CrewAssignmentPhase::query()->find($standbyPhaseId);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($standbyPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($standbyPhase?->status)->toBe(CrewPhaseStatus::Completed)
        ->and($standbyPhase?->actual_start_at?->toDateTimeString())->toBe('2026-09-10 12:00:00')
        ->and($standbyPhase?->actual_end_at?->toDateTimeString())->toBe('2026-09-13 15:00:00');
});

test('cancelled assignment completed standby remains visible to crew timesheet preparation', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['payroll.crew_timesheets.prepare']);

    $fixtures['assignment']->delete();
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($fixtures['company']->id, $fixtures['employee']->id, [
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $fixtures['vessel']->id,
    ], $fixtures['user']->id);
    $id = $assignment->id;

    $service->perform($fixtures['company']->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-07-01 08:00:00',
    ], $fixtures['user']->id);
    $service->perform($fixtures['company']->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-07-01 12:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
    ], $fixtures['user']->id);
    $service->perform($fixtures['company']->id, $id, CrewMovementAction::CancelAssignment, [
        'reason' => 'Mobilisation cancelled after standby',
        'occurred_at' => '2026-07-03 18:00:00',
    ], $fixtures['user']->id);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $standbyLines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->where('pay_category', CrewTimesheetPayCategory::SignOnStandby->value)
        ->where('days', '>', 0)
        ->get();

    expect($standbyLines)->not->toBeEmpty()
        ->and($standbyLines->sum('days'))->toBeGreaterThan(0);
});

test('actual movement actions reject future occurred_at timestamps', function (string $action, array $extraPayload) {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementIntegrityFixtures();
    $service = app(CrewMovementService::class);
    $sourceVessel = makeCrewMovementVessel('Future Guard Source');
    $destinationVessel = makeCrewMovementVessel('Future Guard Destination');

    $assignment = match ($action) {
        'join_vessel', 'transfer_vessel', 'confirm_disembarkation', 'plan_signoff' => makeActiveOnVesselAssignment(
            $company,
            $employee,
            $rank,
            $sourceVessel,
        ),
        default => tap(
            $service->createDraft($company->id, $employee->id, ['rank_id' => $rank->id], $user->id),
            function (CrewAssignment $draft) use ($service, $company, $user): void {
                if ($draft->currentPhase?->phase_code === CrewPhaseCode::PreMobilisation) {
                    $service->perform($company->id, $draft->id, CrewMovementAction::ApproveMobilisation, [
                        'occurred_at' => '2026-09-10 08:00:00',
                    ], $user->id);
                }
            },
        ),
    };

    if ($action === 'transfer_vessel') {
        $extraPayload['vessel_id'] = $destinationVessel->id;
        $extraPayload['rank_id'] = $rank->id;
    }

    if ($action === 'join_vessel') {
        $extraPayload['vessel_id'] = $sourceVessel->id;
        $extraPayload['rank_id'] = $rank->id;
        $extraPayload['planned_signoff_choice'] = 'tour_of_duty';
    }

    if ($action === 'confirm_disembarkation') {
        $extraPayload['next_phase'] = CrewPhaseCode::DemobStandby->value;
    }

    if ($action === 'cancel_assignment') {
        $extraPayload['reason'] = 'Future cancel attempt';
    }

    $assignment = $assignment->fresh(['currentPhase']);

    $beforePhaseId = $assignment->current_phase_id;
    $beforeStatus = $assignment->status;
    $beforePhaseCount = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->count();
    $beforeActiveAssignments = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('status', CrewAssignmentStatus::Active)
        ->count();

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), array_merge([
            'action' => $action,
            'occurred_at' => '2027-08-01 08:00:00',
        ], $extraPayload))
        ->assertSessionHasErrors('occurred_at');

    $assignment->refresh();

    expect($assignment->current_phase_id)->toBe($beforePhaseId)
        ->and($assignment->status)->toBe($beforeStatus)
        ->and(CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->count())->toBe($beforePhaseCount)
        ->and(CrewAssignment::query()
            ->where('company_id', $company->id)
            ->where('employee_id', $employee->id)
            ->where('status', CrewAssignmentStatus::Active)
            ->count())->toBe($beforeActiveAssignments);
})->with([
    'join vessel' => ['join_vessel', []],
    'confirm disembarkation' => ['confirm_disembarkation', []],
    'transfer vessel' => ['transfer_vessel', []],
    'cancel assignment' => ['cancel_assignment', []],
]);

test('future planned sign-off remains allowed while actual movement timestamps are rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementIntegrityFixtures();
    $vessel = makeCrewMovementVessel('Future Plan Signoff Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::PlanSignoff->value,
            'planned_signoff_at' => '2026-12-20',
            'planned_signoff_override_reason' => 'Extended contract',
        ])
        ->assertRedirect();

    expect($assignment->fresh()->planned_signoff_at?->toDateString())->toBe('2026-12-20');
});

test('movement correction rejects future actual timestamps', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.corrections.request',
    ]);
    $vessel = makeCrewMovementVessel('Correction Future Guard Vessel', $fixtures['company']);
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;
    $user = $fixtures['user'];

    expect(fn () => app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $user,
        [
            'actual_start_at' => '2027-08-01 08:00:00',
            'remarks' => 'Future correction attempt',
        ],
        'Attempted future correction',
    ))->toThrow(CrewMovementException::class, 'Actual movement events cannot be recorded in the future.');
});

test('cancelled assignment with completed standby does not remain active in current crew status', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $service = app(CrewMovementService::class);
    $vessel = makeCrewMovementVessel('Status Resolver Vessel', $company);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-10 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-10 12:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::CancelAssignment, [
        'reason' => 'Cancelled after standby',
        'occurred_at' => '2026-09-13 15:00:00',
    ], $user->id);

    $status = app(CrewAssignmentStatusResolver::class)->forEmployee($employee->fresh());

    expect($status['has_active_assignment'])->toBeFalse()
        ->and($status['status'])->not->toBe('join_standby');
});
