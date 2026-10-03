<?php

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Position;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Validation\ValidationException;

test('cross-company draft assignment show returns 404 even with planning permissions', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank, 'user' => $otherUser] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Foreign Draft Vessel', $otherCompany);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $foreign = app(CrewMovementService::class)->createDraft($otherCompany->id, $otherEmployee->id, [
        'position_id' => $otherRank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $otherUser->id);

    expect($foreign->status)->toBe(CrewAssignmentStatus::Draft);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $foreign))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $foreign))
        ->assertNotFound();
});

test('hidden employee draft assignment show returns 404 for assignment viewer', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'officeEmployee' => $officeEmployee,
    ] = makeEmployeeVisibilityFixtures();

    $rank = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Access Order Rank '.uniqid(),
        'status' => 'active', 'is_crew_position' => true,
    ]);
    $position = ensureRankMappedPosition($company, $rank);
    $officeEmployee->update(['position_id' => $rank->id, 'position_id' => $position->id]);
    $vessel = makeCrewMovementVessel('Hidden Draft Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
        'crew_operations.assignments.create',
        'crew_operations.planning.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $hidden = app(CrewMovementService::class)->createDraft($company->id, $officeEmployee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    expect(EmployeeVisibilityScope::canAccess($user, $officeEmployee, $company->id))->toBeFalse()
        ->and($hidden->status)->toBe(CrewAssignmentStatus::Draft);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $hidden))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $hidden))
        ->assertNotFound();
});

test('planning permissions alone do not grant crew assignment access', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Only Access Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.update',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $draft = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ]);

    expect($draft)->toBeInstanceOf(CrewAssignment::class);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $draft))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $draft))
        ->assertForbidden();
});

test('visible same-company draft assignment without permission returns forbidden', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Visible Draft Vessel', $company);

    grantCompanyPermissions($user, $company, ['employees.view']);
    $user->update(['current_company_id' => $company->id]);

    $draft = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ]);

    expect($draft)->toBeInstanceOf(CrewAssignment::class);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $draft))
        ->assertForbidden();
});

test('updateAssignment locks employee before assignment and still rechecks planning conflicts', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vesselA = makeCrewMovementVessel('Lock Order Vessel A', $company);
    $vesselB = makeCrewMovementVessel('Lock Order Vessel B', $company);
    $service = app(CrewMovementService::class);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vesselA->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $active = $service->startAssignment($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vesselB->id,
        'planned_join_at' => '2026-09-01',
        'planned_signoff_at' => '2026-09-30',
        'stage_started_at' => '2026-09-01 08:00:00',
    ], $user->id);

    expect(fn () => $service->updateAssignment($company->id, $active->id, [
        'planned_join_at' => '2026-09-01',
        'planned_signoff_at' => '2026-10-15',
        'vessel_id' => $vesselB->id,
        'position_id' => $rank->id,
    ], $user->id, $user))->toThrow(ValidationException::class);

    expect($active->fresh()->status)->toBe(CrewAssignmentStatus::Active)
        ->and($active->fresh()->planned_signoff_at?->toDateString())->toBe('2026-09-30');
});
