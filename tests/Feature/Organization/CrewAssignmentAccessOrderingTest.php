<?php

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Models\Rank;
use App\Models\User;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Validation\ValidationException;

test('cross-company planned assignment show returns 404 for planning viewer', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank, 'user' => $otherUser] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Foreign Plan Vessel', $otherCompany);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $foreign = app(CrewMovementService::class)->createPlanned($otherCompany->id, $otherEmployee->id, [
        'rank_id' => $otherRank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $otherUser->id);

    expect($foreign->status)->toBe(CrewAssignmentStatus::Planned);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $foreign))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $foreign))
        ->assertNotFound();
});

test('hidden employee planned assignment show returns 404 for planning viewer', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'officeEmployee' => $officeEmployee,
    ] = makeEmployeeVisibilityFixtures();

    $rank = Rank::query()->create([
        'name' => 'Access Order Rank '.uniqid(),
        'is_active' => true,
    ]);
    $officeEmployee->update(['rank_id' => $rank->id]);
    $vessel = makeCrewMovementVessel('Hidden Plan Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $actor = User::factory()->create();
    grantCompanyPermissions($actor, $company, [
        'crew_operations.planning.create',
        'crew_operations.planning.view',
    ]);

    $hidden = app(CrewMovementService::class)->createPlanned($company->id, $officeEmployee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $actor->id);

    expect(EmployeeVisibilityScope::canAccess($user, $officeEmployee, $company->id))->toBeFalse()
        ->and($hidden->status)->toBe(CrewAssignmentStatus::Planned);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $hidden))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $hidden))
        ->assertNotFound();
});

test('visible same-company planned assignment without permission returns forbidden', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Visible Plan Vessel', $company);

    // Visibility OK (SCOPE_ALL via role) but no crew assignment/planning view permission.
    grantCompanyPermissions($user, $company, ['employees.view']);
    $user->update(['current_company_id' => $company->id]);

    $planned = app(CrewMovementService::class)->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ]);

    expect($planned)->toBeInstanceOf(CrewAssignment::class);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $planned))
        ->assertForbidden();
});

test('updateAssignment locks employee before assignment and still rechecks conflicts', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vesselA = makeCrewMovementVessel('Lock Order Vessel A', $company);
    $vesselB = makeCrewMovementVessel('Lock Order Vessel B', $company);
    $service = app(CrewMovementService::class);

    $existing = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vesselA->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $editable = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vesselB->id,
        'planned_join_at' => '2026-12-01',
        'planned_signoff_at' => '2027-01-15',
    ], $user->id);

    expect(fn () => $service->updateAssignment($company->id, $editable->id, [
        'planned_join_at' => '2026-10-15',
        'planned_signoff_at' => '2026-11-20',
        'vessel_id' => $vesselB->id,
        'rank_id' => $rank->id,
    ], $user->id, $user))->toThrow(ValidationException::class);

    expect($existing->fresh()->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($editable->fresh()->planned_join_at?->toDateString())->toBe('2026-12-01');
});
