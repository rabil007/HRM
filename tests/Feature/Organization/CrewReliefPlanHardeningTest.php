<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewReliefStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use App\Support\CrewPlanning\SaveCrewPlanningAssignment;
use Illuminate\Validation\ValidationException;

function reliefPlanningPayload(CrewAssignment $source, array $overrides = []): array
{
    return array_merge([
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => null,
        'planned_join_date' => now()->addDays(10)->toDateString(),
        'planned_leave_date' => now()->addDays(100)->toDateString(),
        'relieves_crew_assignment_id' => $source->id,
    ], $overrides);
}

function actingPlanningCreator(array $fixtures): void
{
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.planning.create',
        'crew_operations.planning.update',
        'crew_operations.planning.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
}

it('creates a valid vacant relief plan', function () {
    $fixtures = makeCrewAssignmentFixtures();
    actingPlanningCreator($fixtures);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Vacant Relief Vessel'),
    );

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-planning.assignments.store'), reliefPlanningPayload($source))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $plan = CrewPlanningAssignment::query()
        ->where('relieves_crew_assignment_id', $source->id)
        ->first();

    expect($plan)->not->toBeNull()
        ->and($plan->employee_id)->toBeNull()
        ->and((new CrewReliefReadinessResolver)->forSourceAssignment($source->fresh())->status)
        ->toBe(CrewReliefStatus::ReliefPlanned);
});

it('rejects vacant relief when source is not active P4', function () {
    $fixtures = makeCrewAssignmentFixtures();
    actingPlanningCreator($fixtures);
    $vessel = makeCrewMovementVessel('Non P4 Vacant Vessel');
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $source->currentPhase->update([
        'phase_code' => CrewPhaseCode::ReadyToJoin,
        'status' => CrewPhaseStatus::Active,
    ]);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-planning.assignments.store'), reliefPlanningPayload($source))
        ->assertSessionHasErrors('relieves_crew_assignment_id');
});

it('rejects vacant relief with wrong vessel or rank', function () {
    $fixtures = makeCrewAssignmentFixtures();
    actingPlanningCreator($fixtures);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Correct Vacant Vessel'),
    );
    $otherVessel = makeCrewMovementVessel('Wrong Vacant Vessel');
    $otherRank = Rank::query()->create([
        'name' => 'Wrong Relief Rank '.uniqid(),
        'is_active' => true,
    ]);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-planning.assignments.store'), reliefPlanningPayload($source, [
            'vessel_id' => $otherVessel->id,
        ]))
        ->assertSessionHasErrors('relieves_crew_assignment_id');

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-planning.assignments.store'), reliefPlanningPayload($source, [
            'rank_id' => $otherRank->id,
        ]))
        ->assertSessionHasErrors('relieves_crew_assignment_id');
});

it('rejects duplicate vacant relief plans', function () {
    $fixtures = makeCrewAssignmentFixtures();
    actingPlanningCreator($fixtures);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Dup Vacant Vessel'),
    );

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-planning.assignments.store'), reliefPlanningPayload($source))
        ->assertRedirect();

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-planning.assignments.store'), reliefPlanningPayload($source, [
            'planned_join_date' => now()->addDays(12)->toDateString(),
        ]))
        ->assertSessionHasErrors('relieves_crew_assignment_id');
});

it('keeps vacant relief company-scoped', function () {
    $fixtures = makeCrewAssignmentFixtures();
    actingPlanningCreator($fixtures);
    $other = makeCrewAssignmentFixtures();
    $foreignSource = makeActiveOnVesselAssignment(
        $other['company'],
        $other['employee'],
        $other['rank'],
        makeCrewMovementVessel('Foreign Vacant Vessel'),
    );

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $foreignSource->vessel_id,
            'rank_id' => $foreignSource->rank_id,
            'employee_id' => null,
            'planned_join_date' => now()->addDays(10)->toDateString(),
            'planned_leave_date' => now()->addDays(100)->toDateString(),
            'relieves_crew_assignment_id' => $foreignSource->id,
        ])
        ->assertSessionHasErrors('relieves_crew_assignment_id');
});

it('rejects duplicates inside the locked SaveCrewPlanningAssignment transaction', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Txn Dup Vessel'),
    );
    $save = app(SaveCrewPlanningAssignment::class);

    $save->create((int) $fixtures['company']->id, reliefPlanningPayload($source));

    expect(fn () => $save->create((int) $fixtures['company']->id, reliefPlanningPayload($source)))
        ->toThrow(ValidationException::class);
});

it('allows updating a relief plan without treating itself as a duplicate', function () {
    $fixtures = makeCrewAssignmentFixtures();
    actingPlanningCreator($fixtures);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Self Update Vessel'),
    );
    $save = app(SaveCrewPlanningAssignment::class);
    $plan = $save->create((int) $fixtures['company']->id, reliefPlanningPayload($source));

    $updated = $save->update($plan, (int) $fixtures['company']->id, [
        'planned_join_date' => now()->addDays(14)->toDateString(),
        'planned_leave_date' => now()->addDays(110)->toDateString(),
        'relieves_crew_assignment_id' => $source->id,
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
    ]);

    expect($updated->planned_join_date->toDateString())->toBe(now()->addDays(14)->toDateString())
        ->and(CrewPlanningAssignment::query()->where('relieves_crew_assignment_id', $source->id)->count())->toBe(1);
});

it('allows replacement planning after cancelled completed or soft-deleted relief and preserves history', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $save = app(SaveCrewPlanningAssignment::class);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Lifecycle Vessel'),
    );
    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);

    $cancelledPlan = $save->create((int) $fixtures['company']->id, reliefPlanningPayload($source, [
        'employee_id' => $reliefEmployee->id,
    ]));
    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($cancelledPlan, $fixtures['user']->id);
    $linked->update(['status' => CrewAssignmentStatus::Cancelled]);

    expect((new CrewReliefReadinessResolver)->forSourceAssignment($source->fresh())->status)
        ->toBe(CrewReliefStatus::NoRelief);

    $replacement = $save->create((int) $fixtures['company']->id, reliefPlanningPayload($source));
    expect($replacement->id)->not->toBe($cancelledPlan->id)
        ->and(CrewPlanningAssignment::withTrashed()->whereKey($cancelledPlan->id)->exists())->toBeTrue();

    $linked->update(['status' => CrewAssignmentStatus::Completed]);
    // Completed linked plan on the first row still exists; replacement is the active one.
    expect((new CrewReliefReadinessResolver)->isOperationallyActive($cancelledPlan->fresh(['crewAssignment'])))
        ->toBeFalse();

    $replacement->delete();
    expect(CrewPlanningAssignment::withTrashed()->whereKey($replacement->id)->exists())->toBeTrue()
        ->and((new CrewReliefReadinessResolver)->forSourceAssignment($source->fresh())->status)
        ->toBe(CrewReliefStatus::NoRelief);

    $afterSoftDelete = $save->create((int) $fixtures['company']->id, reliefPlanningPayload($source, [
        'planned_join_date' => now()->addDays(15)->toDateString(),
    ]));
    expect($afterSoftDelete->relieves_crew_assignment_id)->toBe($source->id)
        ->and(CrewPlanningAssignment::withTrashed()->where('relieves_crew_assignment_id', $source->id)->count())
        ->toBeGreaterThanOrEqual(2);
});

it('treats completed linked relief as non-blocking for a new plan', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $save = app(SaveCrewPlanningAssignment::class);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Completed Lifecycle Vessel'),
    );
    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);

    $plan = $save->create((int) $fixtures['company']->id, reliefPlanningPayload($source, [
        'employee_id' => $reliefEmployee->id,
    ]));
    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($plan, $fixtures['user']->id);
    $linked->update(['status' => CrewAssignmentStatus::Completed]);
    CrewAssignmentPhase::query()->where('crew_assignment_id', $linked->id)->update([
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Completed,
    ]);

    $next = $save->create((int) $fixtures['company']->id, reliefPlanningPayload($source));

    expect($next->id)->not->toBe($plan->id)
        ->and($plan->fresh())->not->toBeNull()
        ->and($linked->fresh()->status)->toBe(CrewAssignmentStatus::Completed);
});
