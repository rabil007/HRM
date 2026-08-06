<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewTourOfDutySource;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\CrewMovements\Corrections\ApproveCrewMovementCorrection;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use App\Support\CrewMovements\CrewAssignmentInvariantGuard;
use App\Support\CrewPlanning\SyncPlanningAssignmentFromCrewAssignment;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

it('recalculates tour-derived planned sign-off when approved p4 start changes', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
    ]);

    $approver = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $approver->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $approver->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($approver, $fixtures['company'], [
        'crew_operations.corrections.view',
        'crew_operations.corrections.approve',
    ]);

    $vessel = makeCrewMovementVessel('Tour Correction Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
        [
            'tour_of_duty_days' => 90,
            'tour_of_duty_source' => CrewTourOfDutySource::GlobalRankDefault->value,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => '2026-04-01 00:00:00',
        ],
    );
    $phase = $assignment->currentPhase;
    $phase->update(['planned_end_at' => '2026-04-01 00:00:00']);
    app(SyncPlanningAssignmentFromCrewAssignment::class)->sync($assignment->fresh());

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $requester,
        ['actual_start_at' => '2026-02-01 08:00:00'],
        'Join date was recorded incorrectly',
    );

    expect($phase->fresh()->actual_start_at?->toDateString())->toBe('2026-01-03')
        ->and($assignment->fresh()->planned_signoff_at?->toDateString())->toBe('2026-04-01')
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->value('planned_leave_date')?->toDateString())
        ->toBe('2026-04-01');

    $this->actingAs($approver)
        ->post(route('organization.crew-movement-corrections.approve', $correction), [
            'decision_notes' => 'Confirmed with vessel master',
        ])
        ->assertRedirect();

    $assignment->refresh();
    $phase->refresh();
    $planning = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($correction->fresh()->status)->toBe(CrewMovementCorrectionStatus::Approved)
        ->and($phase->actual_start_at?->timezone($fixtures['company']->timezone)->toDateString())->toBe('2026-02-01')
        ->and($assignment->tour_of_duty_days)->toBe(90)
        ->and($assignment->planned_signoff_at?->timezone($fixtures['company']->timezone)->toDateString())->toBe('2026-05-02')
        ->and($phase->planned_end_at?->timezone($fixtures['company']->timezone)->toDateString())->toBe('2026-05-02')
        ->and($planning?->planned_leave_date?->toDateString())->toBe('2026-05-02')
        ->and(Activity::query()
            ->where('subject_type', $assignment::class)
            ->where('subject_id', $assignment->id)
            ->where('description', 'Planned Sign-Off recalculated after P4 start correction')
            ->exists())->toBeTrue();
});

it('preserves manual planned sign-off when p4 start is corrected', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
    ]);

    $approver = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $approver->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $approver->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($approver, $fixtures['company'], [
        'crew_operations.corrections.view',
        'crew_operations.corrections.approve',
    ]);

    $vessel = makeCrewMovementVessel('Manual Signoff Correction Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
        [
            'tour_of_duty_days' => 90,
            'tour_of_duty_source' => CrewTourOfDutySource::GlobalRankDefault->value,
            'planned_signoff_source' => CrewPlannedSignoffSource::ManualOverride->value,
            'planned_signoff_at' => '2026-03-15 00:00:00',
            'planned_signoff_override_reason' => 'Contract end',
        ],
    );
    $phase = $assignment->currentPhase;
    $phase->update(['planned_end_at' => '2026-03-15 00:00:00']);
    app(SyncPlanningAssignmentFromCrewAssignment::class)->sync($assignment->fresh());

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $requester,
        ['actual_start_at' => '2026-01-10 08:00:00'],
        'Adjust join',
    );

    $this->actingAs($approver)
        ->post(route('organization.crew-movement-corrections.approve', $correction))
        ->assertRedirect();

    $assignment->refresh();
    $phase->refresh();
    $planning = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($assignment->planned_signoff_at?->timezone($fixtures['company']->timezone)->toDateString())->toBe('2026-03-15')
        ->and($assignment->planned_signoff_source)->toBe(CrewPlannedSignoffSource::ManualOverride)
        ->and($phase->planned_end_at?->timezone($fixtures['company']->timezone)->toDateString())->toBe('2026-03-15')
        ->and($planning?->planned_leave_date?->toDateString())->toBe('2026-03-15');
});

it('preserves existing_plan sign-off when p4 start is corrected', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $vessel = makeCrewMovementVessel('Existing Plan Correction Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
        [
            'tour_of_duty_days' => 75,
            'tour_of_duty_source' => CrewTourOfDutySource::CompanyRankPolicy->value,
            'planned_signoff_source' => CrewPlannedSignoffSource::ExistingPlan->value,
            'planned_signoff_at' => '2026-11-08 00:00:00',
        ],
    );
    $phase = $assignment->currentPhase;
    $phase->update(['planned_end_at' => '2026-11-08 00:00:00']);
    app(SyncPlanningAssignmentFromCrewAssignment::class)->sync($assignment->fresh());

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $requester,
        ['actual_start_at' => '2026-08-14 08:00:00'],
        'Adjust join',
    );

    app(ApproveCrewMovementCorrection::class)->handle(
        $correction,
        $requester,
        (int) $fixtures['company']->id,
    );

    $assignment->refresh();
    $phase->refresh();
    $planning = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($assignment->tour_of_duty_days)->toBe(75)
        ->and($assignment->planned_signoff_source)->toBe(CrewPlannedSignoffSource::ExistingPlan)
        ->and($assignment->planned_signoff_at?->timezone($fixtures['company']->timezone)->toDateString())->toBe('2026-11-08')
        ->and($phase->planned_end_at?->timezone($fixtures['company']->timezone)->toDateString())->toBe('2026-11-08')
        ->and($planning?->planned_leave_date?->toDateString())->toBe('2026-11-08');
});

it('rolls back tour recalculation when approval fails after apply', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $vessel = makeCrewMovementVessel('Rollback Tour Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
        [
            'tour_of_duty_days' => 90,
            'tour_of_duty_source' => CrewTourOfDutySource::GlobalRankDefault->value,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => '2026-04-01 00:00:00',
        ],
    );
    $phase = $assignment->currentPhase;
    $phase->update(['planned_end_at' => '2026-04-01 00:00:00']);
    $originalStart = $phase->actual_start_at?->toDateTimeString();

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $requester,
        ['actual_start_at' => '2026-02-01 08:00:00'],
        'Adjust join',
    );

    $this->mock(CrewAssignmentInvariantGuard::class, function ($mock) {
        $mock->shouldReceive('assertValid')->andThrow(new RuntimeException('Forced invariant failure'));
    });

    expect(fn () => app(ApproveCrewMovementCorrection::class)->handle(
        $correction->fresh(),
        $requester,
        (int) $fixtures['company']->id,
    ))->toThrow(RuntimeException::class);

    $assignment->refresh();
    $phase->refresh();

    expect($correction->fresh()->status->value)->toBe('pending')
        ->and($phase->actual_start_at?->toDateTimeString())->toBe($originalStart)
        ->and($assignment->planned_signoff_at?->toDateString())->toBe('2026-04-01')
        ->and($phase->planned_end_at?->toDateString())->toBe('2026-04-01');
});
