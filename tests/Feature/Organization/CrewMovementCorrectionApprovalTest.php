<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\EmployeeSeaService;
use App\Models\User;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use Illuminate\Support\Facades\DB;

/**
 * @return array{
 *     requester: User,
 *     approver: User,
 *     company: Company,
 *     assignment: CrewAssignment,
 *     phase: CrewAssignmentPhase,
 *     correction: CrewMovementCorrection
 * }
 */
function makePendingCorrectionPair(bool $completedP4 = false): array
{
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

    $vessel = makeCrewMovementVessel('Approval Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;

    if ($completedP4) {
        $phase->update([
            'status' => CrewPhaseStatus::Completed,
            'actual_end_at' => $phase->actual_start_at->copy()->addDays(30),
        ]);
        $assignment->update([
            'status' => CrewAssignmentStatus::Completed,
            'closed_at' => $phase->actual_end_at,
            'current_phase_id' => null,
        ]);
        $home = CrewAssignmentPhase::query()->create([
            'company_id' => $fixtures['company']->id,
            'crew_assignment_id' => $assignment->id,
            'phase_code' => CrewPhaseCode::HomeRedeploy,
            'sequence' => 2,
            'status' => CrewPhaseStatus::Active,
            'actual_start_at' => $phase->actual_end_at,
        ]);
        $assignment->update(['current_phase_id' => $home->id]);
        $assignment->refresh();
        $phase->refresh();
    }

    $proposedStart = $phase->actual_start_at->copy()->addDay()->timezone($fixtures['company']->timezone)->format('Y-m-d H:i');
    $payload = ['actual_start_at' => $proposedStart];

    if ($completedP4) {
        $payload['actual_end_at'] = $phase->actual_end_at->copy()->timezone($fixtures['company']->timezone)->format('Y-m-d H:i');
    }

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $requester,
        $payload,
        'Correct join date',
    );

    return [
        'requester' => $requester,
        'approver' => $approver,
        'company' => $fixtures['company'],
        'assignment' => $assignment->fresh(['phases', 'currentPhase']),
        'phase' => $phase->fresh(),
        'correction' => $correction,
    ];
}

test('approving a correction applies proposed values atomically', function () {
    ['approver' => $approver, 'assignment' => $assignment, 'phase' => $phase, 'correction' => $correction, 'company' => $company] = makePendingCorrectionPair();
    $expectedStart = $phase->actual_start_at->copy()->addDay();

    $this->actingAs($approver)
        ->post(route('organization.crew-movement-corrections.approve', $correction), [
            'decision_notes' => 'Verified against vessel log',
        ])
        ->assertRedirect(route('organization.crew-movement-corrections.show', $correction));

    $correction->refresh();
    $phase->refresh();

    expect($correction->status)->toBe(CrewMovementCorrectionStatus::Approved)
        ->and($correction->decided_by)->toBe($approver->id)
        ->and($correction->applied_values)->not->toBeNull()
        ->and($phase->actual_start_at->equalTo($expectedStart->timezone($company->timezone)))->toBeTrue();
});

test('rejecting a correction leaves official data unchanged', function () {
    ['approver' => $approver, 'phase' => $phase, 'correction' => $correction] = makePendingCorrectionPair();
    $originalStart = $phase->actual_start_at->toIso8601String();

    $this->actingAs($approver)
        ->post(route('organization.crew-movement-corrections.reject', $correction), [
            'decision_notes' => 'Insufficient evidence',
        ])
        ->assertRedirect();

    $correction->refresh();
    $phase->refresh();

    expect($correction->status)->toBe(CrewMovementCorrectionStatus::Rejected)
        ->and($phase->actual_start_at->toIso8601String())->toBe($originalStart);
});

test('cancelling a correction leaves official data unchanged', function () {
    ['requester' => $requester, 'phase' => $phase, 'correction' => $correction] = makePendingCorrectionPair();
    $originalStart = $phase->actual_start_at->toIso8601String();

    $this->actingAs($requester)
        ->post(route('organization.crew-movement-corrections.cancel', $correction))
        ->assertRedirect(route('organization.crew-movement-corrections.index'));

    $correction->refresh();
    $phase->refresh();

    expect($correction->status)->toBe(CrewMovementCorrectionStatus::Cancelled)
        ->and($phase->actual_start_at->toIso8601String())->toBe($originalStart);
});

test('double approve is rejected', function () {
    ['approver' => $approver, 'correction' => $correction] = makePendingCorrectionPair();

    $this->actingAs($approver)
        ->post(route('organization.crew-movement-corrections.approve', $correction))
        ->assertRedirect();

    $this->actingAs($approver)
        ->from(route('organization.crew-movement-corrections.show', $correction))
        ->post(route('organization.crew-movement-corrections.approve', $correction))
        ->assertSessionHasErrors('correction');
});

test('approving completed p4 correction syncs sea service', function () {
    ['approver' => $approver, 'assignment' => $assignment, 'phase' => $phase, 'correction' => $correction, 'company' => $company] = makePendingCorrectionPair(completedP4: true);

    expect(EmployeeSeaService::query()->where('crew_assignment_phase_id', $phase->id)->exists())->toBeFalse();

    $this->actingAs($approver)
        ->post(route('organization.crew-movement-corrections.approve', $correction))
        ->assertRedirect();

    $phase->refresh();
    $seaService = EmployeeSeaService::query()->where('crew_assignment_phase_id', $phase->id)->first();

    expect($seaService)->not->toBeNull()
        ->and($seaService->start_date->toDateString())->toBe($phase->actual_start_at->timezone($company->timezone)->toDateString())
        ->and($seaService->end_date->toDateString())->toBe($phase->actual_end_at->timezone($company->timezone)->toDateString())
        ->and($seaService->vessel_id)->toBe($assignment->vessel_id);
});
