<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeSeaService;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use App\Support\CrewPlanning\CrewPlanningGanttQuery;
use App\Support\CrewPlanning\SyncPlanningAssignmentFromCrewAssignment;
use Illuminate\Database\QueryException;

function syncPlanningFromAssignment(): SyncPlanningAssignmentFromCrewAssignment
{
    return app(SyncPlanningAssignmentFromCrewAssignment::class);
}

function advanceAssignmentToReadyToJoin(
    CrewMovementService $service,
    int $companyId,
    int $assignmentId,
    int $userId,
): void {
    $service->perform($companyId, $assignmentId, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $userId);
    $service->perform($companyId, $assignmentId, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-05 10:00:00',
        'next_phase' => 'p2a',
    ], $userId);
    $service->perform($companyId, $assignmentId, CrewMovementAction::MarkReady, [
        'occurred_at' => '2026-01-08 09:00:00',
    ], $userId);
}

test('manual draft with complete planned dates creates one planning row', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Manual Sync Vessel');

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-08-01 00:00:00',
        'planned_signoff_at' => '2026-11-01 00:00:00',
    ], $user->id);

    $planning = syncPlanningFromAssignment()->sync($assignment);

    expect($planning)->not->toBeNull()
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1)
        ->and($planning->planned_join_date->toDateString())->toBe('2026-08-01')
        ->and($planning->planned_leave_date->toDateString())->toBe('2026-11-01')
        ->and($planning->vessel_id)->toBe($vessel->id)
        ->and($planning->rank_id)->toBe($rank->id);
});

test('incomplete pre-p4 assignment does not create planning row', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'planned_join_at' => '2026-08-01 00:00:00',
    ], $user->id);

    expect(syncPlanningFromAssignment()->sync($assignment))->toBeNull()
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('updating planned dates updates the same planning row', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Update Sync Vessel');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-08-01 00:00:00',
        'planned_signoff_at' => '2026-11-01 00:00:00',
    ], $user->id);

    $first = syncPlanningFromAssignment()->sync($assignment);
    $assignment->update([
        'planned_join_at' => '2026-09-01 00:00:00',
        'planned_signoff_at' => '2026-12-01 00:00:00',
    ]);
    $second = syncPlanningFromAssignment()->sync($assignment->fresh(['phases', 'employee', 'company']));

    expect($second->id)->toBe($first->id)
        ->and($second->planned_join_date->toDateString())->toBe('2026-09-01')
        ->and($second->planned_leave_date->toDateString())->toBe('2026-12-01')
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);
});

test('planning to assignment conversion reuses original planning row', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Convert Sync Vessel');

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-03-01',
        'planned_leave_date' => '2027-08-31',
        'notes' => 'Keep notes',
    ]);

    $assignment = app(CreateCrewAssignmentFromPlanning::class)->handle($planning);
    app(CreateCrewAssignmentFromPlanning::class)->handle($planning->fresh());

    expect(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and($planning->fresh()->crew_assignment_id)->toBe($assignment->id)
        ->and($planning->fresh()->notes)->toBe('Keep notes');
});

test('join vessel creates open-ended planning row without sign-off', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Join Open Vessel');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    advanceAssignmentToReadyToJoin($service, $company->id, $assignment->id, $user->id);

    $assignment = $service->perform($company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-10 12:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $planning = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($planning)->not->toBeNull()
        ->and($planning->planned_join_date->toDateString())->toBe('2026-01-10')
        ->and($planning->planned_leave_date)->toBeNull()
        ->and($planning->vessel_id)->toBe($vessel->id)
        ->and($planning->rank_id)->toBe($rank->id)
        ->and($planning->employee_id)->toBe($employee->id)
        ->and($planning->company_id)->toBe($company->id);

    $bars = CrewPlanningGanttQuery::bars($company->id, '2026-01-01', '2026-03-31');
    $bar = collect($bars)->firstWhere('crew_assignment_id', $assignment->id);

    expect($bar)->not->toBeNull()
        ->and($bar['is_open_ended'])->toBeTrue()
        ->and($bar['planned_leave_date'])->toBeNull()
        ->and($bar['end'])->toBe('2026-03-31')
        ->and($bar['start'])->toBe('2026-01-10');
});

test('plan signoff updates existing planning row leave date', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Signoff Sync Vessel');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    advanceAssignmentToReadyToJoin($service, $company->id, $assignment->id, $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-10 12:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $beforeId = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->value('id');

    $service->perform($company->id, $assignment->id, CrewMovementAction::PlanSignoff, [
        'planned_signoff_at' => '2026-06-15 00:00:00',
    ], $user->id);

    $planning = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->first();

    expect(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1)
        ->and($planning->id)->toBe($beforeId)
        ->and($planning->planned_leave_date->toDateString())->toBe('2026-06-15');
});

test('confirm disembarkation replaces leave date and syncs sea service atomically', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Disembark Sync Vessel');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    advanceAssignmentToReadyToJoin($service, $company->id, $assignment->id, $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-10 12:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'planned_signoff_at' => '2026-06-15 00:00:00',
    ], $user->id);

    $service->perform($company->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-04-01 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);

    $planning = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->first();
    $p4 = $assignment->fresh(['phases'])->phases->firstWhere('phase_code', CrewPhaseCode::OnVessel);
    $sea = EmployeeSeaService::query()->where('crew_assignment_phase_id', $p4->id)->first();

    expect($planning->planned_leave_date->toDateString())->toBe('2026-04-01')
        ->and($sea)->not->toBeNull()
        ->and($sea->end_date->toDateString())->toBe('2026-04-01')
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);
});

test('soft deleted linked planning row is restored by sync', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Restore Sync Vessel');

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-08-01 00:00:00',
        'planned_signoff_at' => '2026-11-01 00:00:00',
    ], $user->id);

    $planning = syncPlanningFromAssignment()->sync($assignment);
    $planning->delete();

    expect($planning->fresh()->trashed())->toBeTrue();

    $restored = syncPlanningFromAssignment()->sync($assignment->fresh(['phases', 'employee', 'company']));

    expect($restored->id)->toBe($planning->id)
        ->and($restored->trashed())->toBeFalse()
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);
});

test('cancellation before p4 soft deletes future planning bar', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cancel Pre P4 Vessel');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-08-01 00:00:00',
        'planned_signoff_at' => '2026-11-01 00:00:00',
    ], $user->id);
    syncPlanningFromAssignment()->sync($assignment);

    $service->perform($company->id, $assignment->id, CrewMovementAction::CancelAssignment, [
        'reason' => 'Mobilisation cancelled',
    ], $user->id);

    expect(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0)
        ->and(CrewPlanningAssignment::withTrashed()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);
});

test('historical completed p4 planning row is preserved after later cancel is blocked on vessel', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Preserve P4 Vessel');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    advanceAssignmentToReadyToJoin($service, $company->id, $assignment->id, $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-10 12:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-04-01 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);

    $planningId = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->value('id');

    $service->perform($company->id, $assignment->id, CrewMovementAction::CancelAssignment, [
        'reason' => 'Stop demob early',
    ], $user->id);

    expect(CrewPlanningAssignment::query()->whereKey($planningId)->exists())->toBeTrue()
        ->and(CrewPlanningAssignment::query()->whereKey($planningId)->first()->planned_leave_date->toDateString())->toBe('2026-04-01');
});

test('cross company linked planning row is not updated', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cross Company Vessel');

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-08-01 00:00:00',
        'planned_signoff_at' => '2026-11-01 00:00:00',
    ], $user->id);

    $foreign = CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'crew_assignment_id' => $assignment->id,
        'planned_join_date' => '2025-01-01',
        'planned_leave_date' => '2025-02-01',
        'notes' => 'foreign',
    ]);

    expect(syncPlanningFromAssignment()->sync($assignment->fresh(['phases', 'employee', 'company'])))->toBeNull()
        ->and($foreign->fresh()->planned_join_date->toDateString())->toBe('2025-01-01')
        ->and($foreign->fresh()->notes)->toBe('foreign');
});

test('linked planning rows cannot be manually updated or deleted', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Protected Linked Vessel');

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.update',
        'crew_operations.planning.delete',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-08-01 00:00:00',
        'planned_signoff_at' => '2026-11-01 00:00:00',
    ], $user->id);
    $planning = syncPlanningFromAssignment()->sync($assignment);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $planning), [
            'planned_leave_date' => '2026-12-01',
        ])
        ->assertSessionHasErrors('error');

    $this->actingAs($user)
        ->delete(route('organization.crew-planning.assignments.destroy', $planning))
        ->assertSessionHasErrors('error');

    expect($planning->fresh()->planned_leave_date->toDateString())->toBe('2026-11-01')
        ->and($planning->fresh()->trashed())->toBeFalse();
});

test('unlinked planning rows remain editable', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Editable Unlinked Vessel');

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.update',
        'crew_operations.planning.delete',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-08-01',
        'planned_leave_date' => '2026-11-01',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $planning), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2026-08-01',
            'planned_leave_date' => '2026-12-15',
        ])
        ->assertRedirect();

    expect($planning->fresh()->planned_leave_date->toDateString())->toBe('2026-12-15');
});

test('repeated synchronization remains idempotent under unique crew_assignment_id', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Idempotent Sync Vessel');

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-08-01 00:00:00',
        'planned_signoff_at' => '2026-11-01 00:00:00',
    ], $user->id);

    $sync = syncPlanningFromAssignment();
    $first = $sync->sync($assignment);
    $second = $sync->sync($assignment->fresh(['phases', 'employee', 'company']));
    $third = $sync->sync($assignment->fresh(['phases', 'employee', 'company']));

    expect($first->id)->toBe($second->id)
        ->and($second->id)->toBe($third->id)
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);

    expect(fn () => CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'crew_assignment_id' => $assignment->id,
        'planned_join_date' => '2026-01-01',
        'planned_leave_date' => '2026-02-01',
    ]))->toThrow(QueryException::class);
});

test('current crew store endpoint syncs planning when dates are complete', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Store Sync Vessel');

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-08-01',
            'planned_signoff_at' => '2026-11-01',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);
});
