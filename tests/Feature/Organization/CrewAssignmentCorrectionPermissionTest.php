<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\User;

test('viewer with corrections.view receives permitted correction summary, history, and phase correction flags', function () {
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

    $phase1 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'status' => CrewPhaseStatus::Completed,
        'sequence' => 1,
        'actual_start_at' => now()->subDays(5),
        'actual_end_at' => now()->subDays(4),
    ]);

    $phase2 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 2,
        'actual_start_at' => now()->subDays(4),
    ]);

    $assignment->update(['current_phase_id' => $phase2->id]);

    // Approved correction on phase 1
    CrewMovementCorrection::factory()
        ->forAssignment($assignment, $phase1)
        ->approved()
        ->create([
            'company_id' => $company->id,
            'reason' => 'Approved correction for travel',
            'requested_by' => $user->id,
        ]);

    // Pending correction on phase 2
    $pendingCorrection = CrewMovementCorrection::factory()
        ->forAssignment($assignment, $phase2)
        ->pending()
        ->create([
            'company_id' => $company->id,
            'original_values' => ['actual_start_at' => ['value' => '2026-06-07T11:17:00Z', 'display' => '2026-06-07 11:17']],
            'proposed_values' => ['actual_start_at' => ['value' => '2026-06-21T11:17:00Z', 'display' => '2026-06-21 11:17']],
            'reason' => 'Permitted pending correction reason',
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
            ->where('corrections.pending.0.id', $pendingCorrection->id)
            ->where('corrections.pending.0.reason', 'Permitted pending correction reason')
            ->where('corrections.pending.0.decision_notes', 'Pending review notes')
            ->where('corrections.pending.0.requester.id', $user->id)
            ->has('corrections.history', 2)
            ->where('assignment.phase_timeline.0.id', $phase1->id)
            ->where('assignment.phase_timeline.0.has_pending_correction', false)
            ->where('assignment.phase_timeline.0.has_approved_correction', true)
            ->where('assignment.phase_timeline.1.id', $phase2->id)
            ->where('assignment.phase_timeline.1.has_pending_correction', true)
            ->where('assignment.phase_timeline.1.has_approved_correction', false)
        );
});

test('viewer without corrections.view and without corrections.request does not receive correction history or phase correction flags', function () {
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

    $phase1 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'status' => CrewPhaseStatus::Completed,
        'sequence' => 1,
        'actual_start_at' => now()->subDays(5),
        'actual_end_at' => now()->subDays(4),
    ]);

    $phase2 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 2,
        'actual_start_at' => now()->subDays(4),
    ]);

    $assignment->update(['current_phase_id' => $phase2->id]);

    CrewMovementCorrection::factory()
        ->forAssignment($assignment, $phase1)
        ->approved()
        ->create([
            'company_id' => $company->id,
            'reason' => 'Secret approved correction reason',
            'requested_by' => $requester->id,
        ]);

    CrewMovementCorrection::factory()
        ->forAssignment($assignment, $phase2)
        ->pending()
        ->create([
            'company_id' => $company->id,
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
            ->where('can.request_correction', false)
            ->where('corrections', null)
            ->where('correction_request_context', null)
            ->where('assignment.phase_timeline.0.has_pending_correction', false)
            ->where('assignment.phase_timeline.0.has_approved_correction', false)
            ->where('assignment.phase_timeline.1.has_pending_correction', false)
            ->where('assignment.phase_timeline.1.has_approved_correction', false)
        );

    $content = $response->getContent();
    expect($content)->not->toContain('Secret confidential reason')
        ->and($content)->not->toContain('Secret decision notes')
        ->and($content)->not->toContain('Secret approved correction reason');
});

test('viewer with corrections.request only receives request context with pending flag, hiding approved history and phase flags', function () {
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

    $phase1 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'status' => CrewPhaseStatus::Completed,
        'sequence' => 1,
        'actual_start_at' => now()->subDays(5),
        'actual_end_at' => now()->subDays(4),
        'remarks' => 'Internal sensitive remarks for travel',
        'details' => ['internal_secret' => 'confidential_detail'],
    ]);

    $phase2 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 2,
        'actual_start_at' => now()->subDays(4),
    ]);

    $assignment->update(['current_phase_id' => $phase2->id]);

    CrewMovementCorrection::factory()
        ->forAssignment($assignment, $phase1)
        ->approved()
        ->create([
            'company_id' => $company->id,
            'reason' => 'Secret approved travel correction',
            'requested_by' => $requester->id,
        ]);

    CrewMovementCorrection::factory()
        ->forAssignment($assignment, $phase2)
        ->pending()
        ->create([
            'company_id' => $company->id,
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
            ->where('assignment.phase_timeline.0.has_pending_correction', false)
            ->where('assignment.phase_timeline.0.has_approved_correction', false)
            ->where('assignment.phase_timeline.1.has_pending_correction', true)
            ->where('assignment.phase_timeline.1.has_approved_correction', false)
            ->has('correction_request_context.correctable_phases', 2)
            ->where('correction_request_context.correctable_phases.0.id', $phase1->id)
            ->where('correction_request_context.correctable_phases.0.has_pending_correction', false)
            ->where('correction_request_context.correctable_phases.1.id', $phase2->id)
            ->where('correction_request_context.correctable_phases.1.has_pending_correction', true)
            ->missing('correction_request_context.correctable_phases.0.remarks')
            ->missing('correction_request_context.correctable_phases.0.details')
            ->missing('correction_request_context.correctable_phases.0.actual_start_at')
            ->missing('correction_request_context.correctable_phases.0.actual_end_at')
            ->missing('correction_request_context.pending')
            ->missing('correction_request_context.history')
            ->missing('correction_request_context.reason')
            ->missing('correction_request_context.decision_notes')
            ->missing('correction_request_context.requester')
            ->missing('correction_request_context.decision_maker')
        );

    $content = $response->getContent();
    expect($content)->not->toContain('Confidential audit correction reason')
        ->and($content)->not->toContain('Confidential decision notes')
        ->and($content)->not->toContain('Secret approved travel correction');
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

test('viewer with corrections.override only receives request context with pending flag, hiding approved history and sensitive notes', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $requester = User::factory()->create();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.corrections.override',
    ]);

    $vessel = makeCrewMovementVessel('Perm Vessel Override', $company);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'assignment_no' => 'CA-OVR-ONLY-01',
        'status' => 'active',
        'started_at' => now(),
    ]);

    $phase1 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'status' => CrewPhaseStatus::Completed,
        'sequence' => 1,
        'actual_start_at' => now()->subDays(5),
        'actual_end_at' => now()->subDays(4),
    ]);

    $phase2 = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 2,
        'actual_start_at' => now()->subDays(4),
    ]);

    $assignment->update(['current_phase_id' => $phase2->id]);

    CrewMovementCorrection::factory()
        ->forAssignment($assignment, $phase1)
        ->approved()
        ->create([
            'company_id' => $company->id,
            'reason' => 'Secret override approved travel correction',
            'requested_by' => $requester->id,
        ]);

    CrewMovementCorrection::factory()
        ->forAssignment($assignment, $phase2)
        ->pending()
        ->create([
            'company_id' => $company->id,
            'original_values' => ['actual_start_at' => ['value' => '2026-06-07T11:17:00Z', 'display' => '2026-06-07 11:17']],
            'proposed_values' => ['actual_start_at' => ['value' => '2026-06-21T11:17:00Z', 'display' => '2026-06-21 11:17']],
            'reason' => 'Secret confidential pending note for override test',
            'decision_notes' => 'Confidential decision notes for override test',
            'requested_by' => $requester->id,
        ]);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/crew/show')
            ->where('can.view_corrections', false)
            ->where('can.request_correction', false)
            ->where('can.override_corrections', true)
            ->where('corrections', null)
            ->where('assignment.phase_timeline.0.has_pending_correction', false)
            ->where('assignment.phase_timeline.0.has_approved_correction', false)
            ->where('assignment.phase_timeline.1.has_pending_correction', true)
            ->where('assignment.phase_timeline.1.has_approved_correction', false)
            ->has('correction_request_context.correctable_phases', 2)
            ->where('correction_request_context.correctable_phases.0.id', $phase1->id)
            ->where('correction_request_context.correctable_phases.0.has_pending_correction', false)
            ->where('correction_request_context.correctable_phases.1.id', $phase2->id)
            ->where('correction_request_context.correctable_phases.1.has_pending_correction', true)
        );

    $content = $response->getContent();
    expect($content)->not->toContain('Secret confidential pending note for override test')
        ->and($content)->not->toContain('Confidential decision notes for override test')
        ->and($content)->not->toContain('Secret override approved travel correction');
});

test('requester without corrections.view sees own_pending_correction_id and cancelling redirects to assignment show', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.corrections.request',
    ]);

    $vessel = makeCrewMovementVessel('Perm Vessel Cancel', $company);

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'assignment_no' => 'CA-CANCEL-01',
        'status' => 'active',
        'started_at' => now(),
    ]);

    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $company->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 1,
        'actual_start_at' => now()->subDays(4),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    $pendingCorrection = CrewMovementCorrection::factory()
        ->forAssignment($assignment, $phase)
        ->pending()
        ->create([
            'company_id' => $company->id,
            'reason' => 'My request to cancel',
            'requested_by' => $user->id,
        ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/crew/show')
            ->where('assignment.phase_timeline.0.id', $phase->id)
            ->where('assignment.phase_timeline.0.has_pending_correction', true)
            ->where('assignment.phase_timeline.0.own_pending_correction_id', $pendingCorrection->id)
            ->where('assignment.phase_timeline.0.can_cancel_pending', true)
        );

    // Now cancel the pending correction
    $cancelResponse = $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-movement-corrections.cancel', $pendingCorrection), [
            'decision_notes' => 'Cancelling my mistake',
        ]);

    $cancelResponse->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $cancelResponse->assertSessionHas('success', 'Correction cancelled.');

    expect($pendingCorrection->fresh()->status->value)->toBe(CrewMovementCorrectionStatus::Cancelled->value);
});
