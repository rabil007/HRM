<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewAssignmentEditability;
use App\Support\CrewMovements\CrewMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank}
 */
function makeCrewEditabilityFixtures(array $permissions = [
    'crew_operations.assignments.view',
    'crew_operations.assignments.create',
    'crew_operations.assignments.update',
    'crew_operations.movements.perform',
    'crew_operations.assignments.cancel',
    'crew_operations.corrections.request',
]): array
{
    $fixtures = makeCrewAssignmentFixtures();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], $permissions);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

function makeAssignmentWithPhase(
    Company $company,
    Employee $employee,
    Rank $rank,
    Vessel $vessel,
    CrewPhaseCode $phaseCode,
    CrewAssignmentStatus $status = CrewAssignmentStatus::Active,
): CrewAssignment {
    $started = CarbonImmutable::parse('2026-01-01 08:00:00', $company->timezone ?? 'UTC');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-2026-'.Str::upper(Str::random(6)),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => $status,
        'started_at' => $started,
        'source' => 'manual',
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => $phaseCode,
        'sequence' => 1,
        'status' => $status === CrewAssignmentStatus::Completed ? CrewPhaseStatus::Completed : CrewPhaseStatus::Active,
        'actual_start_at' => $started,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    return $assignment->fresh(['currentPhase', 'vessel', 'employee']);
}

test('1. Draft assignment allows opening edit page and updating planning fields', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('Draft Vessel');

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-09-01',
    ], $user->id);

    expect(CrewAssignmentEditability::isEditable($assignment))->toBeTrue();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/edit')
            ->where('assignment.id', $assignment->id)
            ->where('assignment.is_editable', true));

    $newRank = Rank::query()->create(['name' => 'New Draft Rank '.Str::uuid(), 'is_active' => true]);

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $assignment), [
            'rank_id' => $newRank->id,
            'planned_join_at' => '2026-09-15',
            'remarks' => 'Updated draft planning',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect($assignment->fresh())
        ->rank_id->toBe($newRank->id)
        ->planned_join_at->toDateString()->toBe('2026-09-15')
        ->remarks->toBe('Updated draft planning');
});

test('2. Active P0 assignment is editable', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('P0 Vessel');

    $assignment = makeAssignmentWithPhase($company, $employee, $rank, $vessel, CrewPhaseCode::PreMobilisation);

    expect(CrewAssignmentEditability::isEditable($assignment))->toBeTrue();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $assignment))
        ->assertOk();

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $assignment), [
            'planned_join_at' => '2026-10-01',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect($assignment->fresh()->planned_join_at->toDateString())->toBe('2026-10-01');
});

test('3. Active P1, P2A, P2B, P3 assignments are editable', function (CrewPhaseCode $phaseCode) {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('Pre-P4 Vessel');

    $assignment = makeAssignmentWithPhase($company, $employee, $rank, $vessel, $phaseCode);

    expect(CrewAssignmentEditability::isEditable($assignment))->toBeTrue();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $assignment))
        ->assertOk();

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $assignment), [
            'planned_join_at' => '2026-10-10',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect($assignment->fresh()->planned_join_at->toDateString())->toBe('2026-10-10');
})->with([
    'P1 TravelIn' => CrewPhaseCode::TravelIn,
    'P2A JoinStandby' => CrewPhaseCode::JoinStandby,
    'P2B Training' => CrewPhaseCode::Training,
    'P3 ReadyToJoin' => CrewPhaseCode::ReadyToJoin,
]);

test('4. Active P4 assignment edit route redirects and update is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('P4 Vessel');

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_join_at' => '2026-01-01',
    ]);

    expect(CrewAssignmentEditability::isEditable($assignment))->toBeFalse();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $assignment))
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('error', 'This assignment can no longer be edited directly. Use Movement Actions or Request Correction.');

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $assignment), [
            'planned_join_at' => '2026-12-01',
        ])
        ->assertSessionHasErrors(['error']);

    expect($assignment->fresh()->planned_join_at->toDateString())->toBe('2026-01-01');
});

test('5. P5 Demobilisation Standby assignment is not editable and update is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('P5 Vessel');

    $assignment = makeAssignmentWithPhase($company, $employee, $rank, $vessel, CrewPhaseCode::DemobStandby);

    expect(CrewAssignmentEditability::isEditable($assignment))->toBeFalse();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $assignment))
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $assignment), [
            'remarks' => 'Illegal P5 Edit',
        ])
        ->assertSessionHasErrors(['error']);
});

test('6. P6 Home / Redeployment assignment is not editable and update is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('P6 Vessel');

    $assignment = makeAssignmentWithPhase($company, $employee, $rank, $vessel, CrewPhaseCode::HomeRedeploy);

    expect(CrewAssignmentEditability::isEditable($assignment))->toBeFalse();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $assignment))
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $assignment), [
            'remarks' => 'Illegal P6 Edit',
        ])
        ->assertSessionHasErrors(['error']);
});

test('7. Completed assignment is not editable and update is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('Completed Vessel');

    $assignment = makeAssignmentWithPhase($company, $employee, $rank, $vessel, CrewPhaseCode::HomeRedeploy, CrewAssignmentStatus::Completed);

    expect(CrewAssignmentEditability::isEditable($assignment))->toBeFalse();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $assignment))
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $assignment), [
            'remarks' => 'Illegal Completed Edit',
        ])
        ->assertSessionHasErrors(['error']);
});

test('8. Cancelled assignment is not editable and update is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('Cancelled Vessel');

    $assignment = makeAssignmentWithPhase($company, $employee, $rank, $vessel, CrewPhaseCode::PreMobilisation, CrewAssignmentStatus::Cancelled);

    expect(CrewAssignmentEditability::isEditable($assignment))->toBeFalse();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $assignment))
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $assignment), [
            'remarks' => 'Illegal Cancelled Edit',
        ])
        ->assertSessionHasErrors(['error']);
});

test('9. Crew index payload exposes is_editable true for pre-P4 and false for P4', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('Index Editability Vessel');

    $preP4 = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    $emp2 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $p4 = makeActiveOnVesselAssignment($company, $emp2, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/index')
            ->has('assignments', 2)
            ->where('assignments.0.is_editable', fn (bool $val) => true) // assignments sorted desc by created_at/id
            ->where('assignments.1.is_editable', fn (bool $val) => true));
});

test('10. Assignment show payload exposes is_editable true for P3 and false for P4', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('Show Editability Vessel');

    $p3Assignment = makeAssignmentWithPhase($company, $employee, $rank, $vessel, CrewPhaseCode::ReadyToJoin);
    $p4Assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $p3Assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.is_editable', true));

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $p4Assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.is_editable', false));
});

test('11. User without assignments.update permission cannot access edit or update even for pre-P4', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures([
        'crew_operations.assignments.view',
    ]);

    $draft = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $draft))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $draft), [
            'remarks' => 'No permission attempt',
        ])
        ->assertForbidden();
});

test('12. Direct PUT bypass attempt against P4 is rejected server-side', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('Direct Bypass Vessel');

    $p4 = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $p4), [
            'remarks' => 'Bypass attempt',
        ])
        ->assertSessionHasErrors(['error']);

    expect($p4->fresh()->remarks)->not->toBe('Bypass attempt');
});

test('13. Tenant isolation remains intact for edit and update routes', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmp, 'rank' => $otherRank] = makeCrewAssignmentFixtures();

    $otherDraft = app(CrewMovementService::class)->createDraft($otherCompany->id, $otherEmp->id, [
        'rank_id' => $otherRank->id,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $otherDraft))
        ->assertNotFound();

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $otherDraft), [
            'remarks' => 'Cross-tenant update',
        ])
        ->assertNotFound();
});

test('14. P4 Plan Sign-Off action still works', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('Plan Signoff Vessel');

    $p4 = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $p4), [
            'action' => 'plan_signoff',
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2026-07-01',
            'planned_signoff_override_reason' => 'Contract extension requested by client.',
        ])
        ->assertRedirect();

    expect($p4->fresh()->planned_signoff_at->toDateString())->toBe('2026-07-01');
});

test('15. P4 Request Correction still works', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewEditabilityFixtures();
    $vessel = makeCrewMovementVessel('Correction Request Vessel');

    $p4 = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $p4->currentPhase;
    $proposedStart = $phase->actual_start_at->copy()->addHour()->format('Y-m-d H:i');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.corrections.store', $p4), [
            'crew_assignment_phase_id' => $phase->id,
            'proposed_values' => [
                'actual_start_at' => $proposedStart,
            ],
            'reason' => 'Actual join time was recorded late.',
        ])
        ->assertRedirect();

    expect($p4->corrections()->where('status', 'pending')->exists())->toBeTrue();
});
