<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use Carbon\CarbonImmutable;

it('presenter includes relief readiness fields for on-vessel assignments', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Relief Presenter Vessel');
    $today = CarbonImmutable::now($fixtures['company']->timezone)->startOfDay();

    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => $today->addDays(10)->toDateTimeString(),
        ],
    );

    $payload = CrewAssignmentPresenter::listItem($assignment->fresh([
        'employee', 'rank', 'vessel', 'client', 'companyVisaType', 'currentPhase', 'company',
    ]));

    expect($payload['relief_status'])->toBe(CrewReliefStatus::NoRelief->value)
        ->and($payload['relief_action_label'])->toBe('Plan Relief')
        ->and($payload['days_until_signoff'])->toBe(10);

    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
        'name' => 'Relief Person',
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $fixtures['rank']->id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $assignment->id,
        'planned_join_date' => $today->addDays(10)->toDateString(),
        'planned_leave_date' => $today->addDays(100)->toDateString(),
    ]);

    $withPlan = CrewAssignmentPresenter::listItem($assignment->fresh([
        'employee', 'rank', 'vessel', 'client', 'companyVisaType', 'currentPhase', 'company',
    ]));

    expect($withPlan['relief_status'])->toBe(CrewReliefStatus::ReliefPlanned->value)
        ->and($withPlan['relief_employee']['name'])->toBe('Relief Person');
});

it('resolves assignment_created, mobilising, ready_to_join and relief_onboard', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Status Source Vessel'),
        ['planned_signoff_at' => now()->addDays(20)->toDateTimeString()],
    );
    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => now()->addDays(10)->toDateString(),
        'planned_leave_date' => now()->addDays(100)->toDateString(),
    ]);

    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($planning, $fixtures['user']->id);
    expect($planning->fresh()->relieves_crew_assignment_id)->toBe($source->id)
        ->and((new CrewReliefReadinessResolver)->forSourceAssignment($source->fresh())->status)
        ->toBe(CrewReliefStatus::AssignmentCreated);

    $linked->update(['status' => CrewAssignmentStatus::Active]);
    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::TravelIn,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => now(),
    ]);
    expect((new CrewReliefReadinessResolver)->forSourceAssignment($source->fresh())->status)
        ->toBe(CrewReliefStatus::Mobilising);

    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::ReadyToJoin,
        'status' => CrewPhaseStatus::Active,
    ]);
    $ready = (new CrewReliefReadinessResolver)->forSourceAssignment($source->fresh());
    expect($ready->status)->toBe(CrewReliefStatus::ReadyToJoin)
        ->and($ready->risk)->toBe(CrewReliefRisk::None);

    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => now(),
    ]);
    expect((new CrewReliefReadinessResolver)->forSourceAssignment($source->fresh())->status)
        ->toBe(CrewReliefStatus::ReliefOnboard);
});

it('excludes soft-deleted relief plans', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Deleted Relief Vessel'),
        ['planned_signoff_at' => now()->addDays(5)->toDateTimeString()],
    );
    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $deleted = CrewPlanningAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => now()->addDays(5)->toDateString(),
        'planned_leave_date' => now()->addDays(90)->toDateString(),
    ]);
    $deleted->delete();

    expect((new CrewReliefReadinessResolver)->forSourceAssignment($source->fresh())->status)
        ->toBe(CrewReliefStatus::NoRelief);
});

it('rejects duplicate active relief plans', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $user = $fixtures['user'];
    grantCompanyPermissions($user, $fixtures['company'], [
        'crew_operations.planning.create',
        'crew_operations.planning.update',
    ]);
    $user->update(['current_company_id' => $fixtures['company']->id]);

    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Dup Relief Vessel'),
    );
    $firstRelief = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $secondRelief = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);

    $this->actingAs($user)->post(route('organization.crew-planning.assignments.store'), [
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $firstRelief->id,
        'planned_join_date' => now()->addDays(10)->toDateString(),
        'planned_leave_date' => now()->addDays(100)->toDateString(),
        'relieves_crew_assignment_id' => $source->id,
    ])->assertRedirect();

    $this->actingAs($user)->post(route('organization.crew-planning.assignments.store'), [
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $secondRelief->id,
        'planned_join_date' => now()->addDays(11)->toDateString(),
        'planned_leave_date' => now()->addDays(101)->toDateString(),
        'relieves_crew_assignment_id' => $source->id,
    ])->assertSessionHasErrors('relieves_crew_assignment_id');
});

it('rejects same-employee and inactive relief employees', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $user = $fixtures['user'];
    grantCompanyPermissions($user, $fixtures['company'], [
        'crew_operations.planning.create',
        'crew_operations.planning.update',
    ]);
    $user->update(['current_company_id' => $fixtures['company']->id]);

    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Same Emp Vessel'),
    );

    $this->actingAs($user)->post(route('organization.crew-planning.assignments.store'), [
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $fixtures['employee']->id,
        'planned_join_date' => now()->addDays(10)->toDateString(),
        'planned_leave_date' => now()->addDays(100)->toDateString(),
        'relieves_crew_assignment_id' => $source->id,
    ])->assertSessionHasErrors('employee_id');
});

it('does not rewrite relief planned join when source sign-off changes', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Stable Join Vessel'),
        ['planned_signoff_at' => '2026-09-01 00:00:00'],
    );
    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => '2026-09-01',
        'planned_leave_date' => '2026-12-01',
    ]);

    $source->update(['planned_signoff_at' => '2026-09-15 00:00:00']);

    expect($planning->fresh()->planned_join_date->toDateString())->toBe('2026-09-01');
});

it('allows late planned join while marking critical risk near sign-off', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-06 12:00:00', 'UTC'));

    $fixtures = makeCrewAssignmentFixtures();
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Late Join Vessel'),
        ['planned_signoff_at' => '2026-08-08 00:00:00'],
    );
    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => '2026-08-20',
        'planned_leave_date' => '2026-11-20',
    ]);

    $result = (new CrewReliefReadinessResolver)->forSourceAssignment($source->fresh());

    expect($result->status)->toBe(CrewReliefStatus::ReliefPlanned)
        ->and($result->risk)->toBe(CrewReliefRisk::Critical);

    CarbonImmutable::setTestNow();
});
