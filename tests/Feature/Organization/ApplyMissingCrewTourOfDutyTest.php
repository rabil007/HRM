<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPlannedSignoffSource;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Support\CrewMovements\ApplyMissingCrewTourOfDuty;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CrewTourProgress;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->fixtures = makeCrewAssignmentFixtures();
    $this->user = $this->fixtures['user'];
    $this->company = $this->fixtures['company'];
    $this->employee = $this->fixtures['employee'];
    $this->rank = $this->fixtures['rank'];
    // Initially the rank has NO tour configured.
    $this->rank->update(['max_tour_of_duty_days' => null]);
    $this->vessel = makeCrewMovementVessel('Tour Repair Vessel');
    $this->service = app(CrewMovementService::class);
    $this->action = app(ApplyMissingCrewTourOfDuty::class);

    grantCompanyPermissions($this->user, $this->company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
        'audit.view',
    ]);
});

function createJoinedActiveP4AssignmentWithoutTour(
    CrewMovementService $service,
    Company $company,
    Employee $employee,
    Rank $rank,
    $vessel,
    User $user,
    string $joinDate = '2026-06-01 08:00:00',
    ?string $plannedSignoff = null,
    ?CrewPlannedSignoffSource $signoffSource = null,
    ?string $overrideReason = null,
): CrewAssignment {
    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_signoff_at' => $plannedSignoff,
    ], $user->id);

    $service->perform($company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-05-20 08:00:00',
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-05-25 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);

    $joinPayload = [
        'occurred_at' => $joinDate,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ];

    if ($plannedSignoff !== null) {
        $joinPayload['planned_signoff_choice'] = 'manual_override';
        $joinPayload['planned_signoff_at'] = $plannedSignoff;
        $joinPayload['planned_signoff_override_reason'] = $overrideReason ?? 'Manual schedule';
    }

    $service->perform($company->id, $assignment->id, CrewMovementAction::JoinVessel, $joinPayload, $user->id);

    $assignment->refresh();

    if ($signoffSource !== null) {
        $assignment->update([
            'planned_signoff_source' => $signoffSource,
            'planned_signoff_override_reason' => $overrideReason,
        ]);
    }

    return $assignment;
}

it('repairs active P4 with null snapshot using newly configured Rank Tour', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    expect($assignment->tour_of_duty_days)->toBeNull()
        ->and($assignment->planned_signoff_at)->toBeNull();

    // HR configures Rank Tour to 90 days later
    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $repaired = $this->action->handle($this->company->id, $assignment->id, $this->user->id);

    expect($repaired)->not->toBeNull()
        ->and($repaired->tour_of_duty_days)->toBe(90);
});

it('calculates missing Planned Sign-Off from actual P4 join plus Tour days', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $repaired = $this->action->handle($this->company->id, $assignment->id, $this->user->id);

    // 2026-06-01 + 90 days = 2026-08-30
    expect($repaired->planned_signoff_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-08-30')
        ->and($repaired->planned_signoff_source)->toBe(CrewPlannedSignoffSource::TourOfDuty)
        ->and($repaired->planned_signoff_override_reason)->toBeNull();
});

it('updates active P4 planned_end_at with the calculated sign-off date', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $repaired = $this->action->handle($this->company->id, $assignment->id, $this->user->id);
    $p4 = $repaired->currentPhase;

    expect($p4->planned_end_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-08-30');
});

it('synchronizes linked Crew Planning planned leave date upon repair', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $this->action->handle($this->company->id, $assignment->id, $this->user->id);

    $planning = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($planning)->not->toBeNull()
        ->and($planning->planned_leave_date?->toDateString())->toBe('2026-08-30');
});

it('preserves existing manual Planned Sign-Off and does not overwrite it', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
        plannedSignoff: '2026-09-05 00:00:00',
        signoffSource: CrewPlannedSignoffSource::ManualOverride,
        overrideReason: 'Operational arrangement',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $repaired = $this->action->handle($this->company->id, $assignment->id, $this->user->id);

    expect($repaired->tour_of_duty_days)->toBe(90)
        ->and($repaired->planned_signoff_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-09-05')
        ->and($repaired->planned_signoff_source)->toBe(CrewPlannedSignoffSource::ManualOverride)
        ->and($repaired->planned_signoff_override_reason)->toBe('Operational arrangement');
});

it('preserves existing planned-signoff source and reason when a date already exists', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
        plannedSignoff: '2026-10-15 00:00:00',
        signoffSource: CrewPlannedSignoffSource::ExistingPlan,
        overrideReason: null,
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $repaired = $this->action->handle($this->company->id, $assignment->id, $this->user->id);

    expect($repaired->planned_signoff_source)->toBe(CrewPlannedSignoffSource::ExistingPlan)
        ->and($repaired->planned_signoff_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-10-15');
});

it('does not change an assignment that already has an existing Tour snapshot', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    // Manually set existing snapshot = 60 days
    $assignment->update([
        'tour_of_duty_days' => 60,
        'planned_signoff_at' => Carbon::parse('2026-07-31', $this->company->timezone),
    ]);

    // Rank master is updated to 120 days
    $this->rank->update(['max_tour_of_duty_days' => 120]);

    $result = $this->action->handle($this->company->id, $assignment->id, $this->user->id);

    expect($result)->toBeNull();

    $assignment->refresh();
    expect($assignment->tour_of_duty_days)->toBe(60)
        ->and($assignment->planned_signoff_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-07-31');
});

it('does not alter an existing assignment when Rank Master changes after a valid snapshot', function () {
    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $assignment = $this->service->createDraft($this->company->id, $this->employee->id, [
        'rank_id' => $this->rank->id,
        'vessel_id' => $this->vessel->id,
    ], $this->user->id);

    $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $this->user->id);
    $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p3',
    ], $this->user->id);
    $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-05 08:00:00',
        'vessel_id' => $this->vessel->id,
        'rank_id' => $this->rank->id,
        'planned_signoff_choice' => 'tour_of_duty',
    ], $this->user->id);

    $assignment->refresh();
    expect($assignment->tour_of_duty_days)->toBe(90);

    // Later Rank Master edit
    $this->rank->update(['max_tour_of_duty_days' => 180]);

    $assignment->refresh();
    expect($assignment->tour_of_duty_days)->toBe(90);

    $result = $this->action->handle($this->company->id, $assignment->id, $this->user->id);
    expect($result)->toBeNull();
    expect($assignment->refresh()->tour_of_duty_days)->toBe(90);
});

it('leaves rank without Tour unrepaired', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    // Rank max_tour_of_duty_days is still null
    $result = $this->action->handle($this->company->id, $assignment->id, $this->user->id);

    expect($result)->toBeNull()
        ->and($assignment->refresh()->tour_of_duty_days)->toBeNull();
});

it('cannot repair draft or pre-P4 assignments', function () {
    $draft = $this->service->createDraft($this->company->id, $this->employee->id, [
        'rank_id' => $this->rank->id,
        'vessel_id' => $this->vessel->id,
    ], $this->user->id);

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $result = $this->action->handle($this->company->id, $draft->id, $this->user->id);
    expect($result)->toBeNull()
        ->and($draft->refresh()->tour_of_duty_days)->toBeNull();

    $this->service->perform($this->company->id, $draft->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $this->user->id);

    $resultPreP4 = $this->action->handle($this->company->id, $draft->id, $this->user->id);
    expect($resultPreP4)->toBeNull()
        ->and($draft->refresh()->tour_of_duty_days)->toBeNull();
});

it('cannot repair completed P4 or completed assignments', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    // Disembark and complete
    $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-08-01 08:00:00',
        'next_phase' => 'p6',
    ], $this->user->id);
    $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::CloseAssignment, [
        'occurred_at' => '2026-08-05 08:00:00',
    ], $this->user->id);

    $assignment->refresh();
    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed);

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $result = $this->action->handle($this->company->id, $assignment->id, $this->user->id);
    expect($result)->toBeNull()
        ->and($assignment->refresh()->tour_of_duty_days)->toBeNull();
});

it('cannot repair cancelled assignments', function () {
    $assignment = $this->service->createDraft($this->company->id, $this->employee->id, [
        'rank_id' => $this->rank->id,
        'vessel_id' => $this->vessel->id,
    ], $this->user->id);

    $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::CancelAssignment, [
        'reason' => 'Mobilisation cancelled by client',
    ], $this->user->id);

    expect($assignment->refresh()->status)->toBe(CrewAssignmentStatus::Cancelled);

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $result = $this->action->handle($this->company->id, $assignment->id, $this->user->id);
    expect($result)->toBeNull();
});

it('rejects cross-company assignment IDs', function () {
    $otherFixtures = makeCrewAssignmentFixtures();
    $otherCompany = $otherFixtures['company'];
    $otherEmployee = $otherFixtures['employee'];
    $otherVessel = makeCrewMovementVessel('Other Vessel', $otherCompany);

    $otherAssignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $otherCompany,
        $otherEmployee,
        $this->rank,
        $otherVessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    // Calling with this->company->id on otherAssignment must return null / do nothing
    $result = $this->action->handle($this->company->id, $otherAssignment->id, $this->user->id);
    expect($result)->toBeNull()
        ->and($otherAssignment->refresh()->tour_of_duty_days)->toBeNull();
});

it('rejects unauthorized HTTP user from performing the action', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $unauthorizedUser = User::factory()->create();
    grantCompanyPermissions($unauthorizedUser, $this->company, ['crew_operations.assignments.view']);

    actingAs($unauthorizedUser)
        ->withSession(['current_company_id' => $this->company->id])
        ->post(route('organization.crew-assignments.apply-tour', $assignment))
        ->assertForbidden();

    expect($assignment->refresh()->tour_of_duty_days)->toBeNull();
});

it('allows authorized HTTP user to perform the action', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    actingAs($this->user)
        ->withSession(['current_company_id' => $this->company->id])
        ->post(route('organization.crew-assignments.apply-tour', $assignment))
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success', 'Tour of Duty applied successfully.');

    expect($assignment->refresh()->tour_of_duty_days)->toBe(90)
        ->and($assignment->planned_signoff_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-08-30');
});

it('records audit activity when repair is performed', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $this->action->handle($this->company->id, $assignment->id, $this->user->id);

    $activity = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->company_id)->toBe($this->company->id)
        ->and($activity->causer_id)->toBe($this->user->id)
        ->and($activity->properties->get('event'))->toBe('late_tour_of_duty_applied')
        ->and($activity->properties->get('new_tour_of_duty_days'))->toBe(90)
        ->and($activity->properties->get('reason'))->toBe('late_tour_of_duty_applied');
});

it('performs no mutations during Artisan dry-run', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $this->artisan('crew:apply-missing-tour-of-duty', [
        '--company' => $this->company->id,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run mode: Found 1 eligible assignment(s). No mutations performed.')
        ->assertSuccessful();

    expect($assignment->refresh()->tour_of_duty_days)->toBeNull()
        ->and($assignment->planned_signoff_at)->toBeNull();
});

it('repairs eligible records during Artisan apply', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $this->artisan('crew:apply-missing-tour-of-duty', [
        '--company' => $this->company->id,
    ])
        ->expectsOutputToContain('Successfully applied missing Tour of Duty to 1 assignment(s).')
        ->assertSuccessful();

    expect($assignment->refresh()->tour_of_duty_days)->toBe(90)
        ->and($assignment->planned_signoff_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-08-30');
});

it('is idempotent when Artisan command is executed multiple times', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    // First run applies
    $this->artisan('crew:apply-missing-tour-of-duty', ['--company' => $this->company->id])
        ->expectsOutputToContain('Successfully applied missing Tour of Duty to 1 assignment(s).')
        ->assertSuccessful();

    // Second run is idempotent and finds no eligible records
    $this->artisan('crew:apply-missing-tour-of-duty', ['--company' => $this->company->id])
        ->expectsOutputToContain('No eligible active P4 assignments with missing Tour of Duty found.')
        ->assertSuccessful();

    expect($assignment->refresh()->tour_of_duty_days)->toBe(90);
});

it('prevents --assignment option from accessing another companys assignment', function () {
    $otherFixtures = makeCrewAssignmentFixtures();
    $otherCompany = $otherFixtures['company'];
    $otherEmployee = $otherFixtures['employee'];
    $otherVessel = makeCrewMovementVessel('Other Vessel', $otherCompany);

    $otherAssignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $otherCompany,
        $otherEmployee,
        $this->rank,
        $otherVessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    // Running for this->company with otherAssignment->id
    $this->artisan('crew:apply-missing-tour-of-duty', [
        '--company' => $this->company->id,
        '--assignment' => $otherAssignment->id,
    ])
        ->expectsOutputToContain('No eligible active P4 assignments with missing Tour of Duty found.')
        ->assertSuccessful();

    expect($otherAssignment->refresh()->tour_of_duty_days)->toBeNull();
});

it('calculates tour progress correctly after repair', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $progressBefore = (new CrewTourProgress)->forAssignment($assignment);
    expect($progressBefore['tour_of_duty_days'])->toBeNull()
        ->and($progressBefore['tour_progress_percent'])->toBeNull();

    $repaired = $this->action->handle($this->company->id, $assignment->id, $this->user->id);

    // As of 2026-06-16 (15 days onboard)
    $asOf = Carbon::parse('2026-06-16', $this->company->timezone);
    $progressAfter = (new CrewTourProgress)->forAssignment($repaired, $asOf);

    expect($progressAfter['tour_of_duty_days'])->toBe(90)
        ->and($progressAfter['days_onboard'])->toBe(15)
        ->and($progressAfter['remaining_tour_days'])->toBe(75)
        ->and($progressAfter['tour_progress_percent'])->toBe(16.7);
});

it('removes missing_tour_of_duty attention warning after successful repair', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $warningsBefore = CrewMovementAttentionQuery::forAssignment($assignment);
    $warningCodesBefore = array_column($warningsBefore, 'code');
    expect($warningCodesBefore)->toContain('missing_tour_of_duty');

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $repaired = $this->action->handle($this->company->id, $assignment->id, $this->user->id);

    $warningsAfter = CrewMovementAttentionQuery::forAssignment($repaired->fresh());
    $warningCodesAfter = array_column($warningsAfter, 'code');
    expect($warningCodesAfter)->not->toContain('missing_tour_of_duty');
});

it('exposes tour repair metadata on the assignment show page', function () {
    $assignment = createJoinedActiveP4AssignmentWithoutTour(
        $this->service,
        $this->company,
        $this->employee,
        $this->rank,
        $this->vessel,
        $this->user,
        '2026-06-01 08:00:00',
    );

    $this->rank->update(['max_tour_of_duty_days' => 90]);

    actingAs($this->user)
        ->withSession(['current_company_id' => $this->company->id])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('organization/crew/show')
            ->where('assignment.can_apply_tour_of_duty', true)
            ->where('assignment.current_rank_tour_days', 90)
            ->where('assignment.suggested_planned_signoff_at', '2026-08-30')
        );

    $repaired = $this->action->handle($this->company->id, $assignment->id, $this->user->id);
    expect($repaired)->not->toBeNull();
    expect($repaired->tour_of_duty_days)->toBe(90);

    actingAs($this->user)
        ->withSession(['current_company_id' => $this->company->id])
        ->get(route('organization.crew-assignments.show', $assignment->fresh()))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('organization/crew/show')
            ->where('assignment.can_apply_tour_of_duty', false)
            ->where('assignment.tour_of_duty_days', 90)
        );
});
