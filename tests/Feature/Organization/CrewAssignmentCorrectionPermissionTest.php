<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\User;

test('viewer with corrections.view receives permitted correction summary and history', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.corrections.view',
    ]);

    $vessel = makeCrewMovementVessel('Perm Vessel A', $company);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'assignment_no' => 'CA-CORR-VIEW-01',
        'status' => 'active',
        'started_at' => now(),
    ]);

    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 1,
        'actual_start_at' => now(),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    $correction = CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'pending',
        'original_values' => ['actual_start_at' => ['value' => '2026-06-07T11:17:00Z', 'display' => '2026-06-07 11:17']],
        'proposed_values' => ['actual_start_at' => ['value' => '2026-06-21T11:17:00Z', 'display' => '2026-06-21 11:17']],
        'reason' => 'Permitted correction reason',
        'decision_notes' => 'Pending review notes',
        'requested_by' => $user->id,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/crew/show')
            ->where('can.view_corrections', true)
            ->has('corrections.pending', 1)
            ->where('corrections.pending.0.id', $correction->id)
            ->where('corrections.pending.0.reason', 'Permitted correction reason')
            ->where('corrections.pending.0.decision_notes', 'Pending review notes')
            ->where('corrections.pending.0.requester.id', $user->id)
            ->has('corrections.history', 1)
        );
});

test('viewer without corrections.view does not receive correction history or pending correction records', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $requester = User::factory()->create();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
    ]);

    $vessel = makeCrewMovementVessel('Perm Vessel B', $company);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'assignment_no' => 'CA-NO-CORR-VIEW-01',
        'status' => 'active',
        'started_at' => now(),
    ]);

    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 1,
        'actual_start_at' => now(),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'pending',
        'original_values' => ['actual_start_at' => ['value' => '2026-06-07T11:17:00Z', 'display' => '2026-06-07 11:17']],
        'proposed_values' => ['actual_start_at' => ['value' => '2026-06-21T11:17:00Z', 'display' => '2026-06-21 11:17']],
        'reason' => 'Secret confidential reason',
        'decision_notes' => 'Secret decision notes',
        'requested_by' => $requester->id,
    ]);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/crew/show')
            ->where('can.view_corrections', false)
            ->where('corrections', null)
            ->where('correction_request_context', null)
        );

    $content = $response->getContent();
    expect($content)->not->toContain('Secret confidential reason')
        ->and($content)->not->toContain('Secret decision notes');
});

test('viewer with corrections.request but without corrections.view receives only request context and no correction history', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $requester = User::factory()->create();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.corrections.request',
    ]);

    $vessel = makeCrewMovementVessel('Perm Vessel C', $company);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'assignment_no' => 'CA-REQ-ONLY-01',
        'status' => 'active',
        'started_at' => now(),
    ]);

    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 1,
        'actual_start_at' => now(),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    CrewMovementCorrection::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phase->id,
        'status' => 'pending',
        'original_values' => ['actual_start_at' => ['value' => '2026-06-07T11:17:00Z', 'display' => '2026-06-07 11:17']],
        'proposed_values' => ['actual_start_at' => ['value' => '2026-06-21T11:17:00Z', 'display' => '2026-06-21 11:17']],
        'reason' => 'Confidential audit correction reason',
        'decision_notes' => 'Confidential decision notes',
        'requested_by' => $requester->id,
    ]);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/crew/show')
            ->where('can.view_corrections', false)
            ->where('can.request_correction', true)
            ->where('corrections', null)
            ->has('correction_request_context.correctable_phases', 1)
            ->where('correction_request_context.correctable_phases.0.id', $phase->id)
            ->where('correction_request_context.correctable_phases.0.has_pending_correction', true)
            ->missing('correction_request_context.pending')
            ->missing('correction_request_context.history')
        );

    $content = $response->getContent();
    expect($content)->not->toContain('Confidential audit correction reason')
        ->and($content)->not->toContain('Confidential decision notes');
});

test('viewer without corrections.view and without corrections.request receives no correction data', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
    ]);

    $vessel = makeCrewMovementVessel('Perm Vessel D', $company);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'assignment_no' => 'CA-NONE-01',
        'status' => 'active',
        'started_at' => now(),
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/crew/show')
            ->where('can.view_corrections', false)
            ->where('can.request_correction', false)
            ->where('corrections', null)
            ->where('correction_request_context', null)
        );
});

test('tenant isolation prevents accessing assignment and corrections across companies', function () {
    ['user' => $userA, 'company' => $companyA, 'employee' => $employeeA, 'rank' => $rankA] = makeCrewAssignmentFixtures();
    ['user' => $userB, 'company' => $companyB, 'employee' => $employeeB, 'rank' => $rankB] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($userA, $companyA, [
        'crew_operations.assignments.view',
        'crew_operations.corrections.view',
    ]);

    $vesselB = makeCrewMovementVessel('Vessel B', $companyB);

    $assignmentB = CrewAssignment::factory()->forEmployee($employeeB)->create([
        'company_id' => $companyB->id,
        'rank_id' => $rankB->id,
        'vessel_id' => $vesselB->id,
        'assignment_no' => 'CA-COMP-B-01',
        'status' => 'active',
        'started_at' => now(),
    ]);

    // User A cannot view Company B's assignment
    $this->actingAs($userA)
        ->withSession(['current_company_id' => $companyA->id])
        ->get(route('organization.crew-assignments.show', $assignmentB))
        ->assertNotFound();
});
