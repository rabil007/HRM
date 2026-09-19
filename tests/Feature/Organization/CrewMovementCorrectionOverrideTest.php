<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Exceptions\CrewMovementException;
use App\Models\Company;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\Hotel;
use App\Models\Rank;
use App\Models\RoomType;
use App\Models\User;
use App\Support\CrewMovements\Corrections\ApproveCrewMovementCorrection;
use App\Support\CrewMovements\Corrections\OverrideCrewMovementCorrection;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use App\Support\CrewMovements\CrewAssignmentInvariantGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../../Support/privileged-two-factor.php';

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     assignment: CrewAssignment,
 *     phase: CrewAssignmentPhase
 * }
 */
function makeOverrideTestFixtures(bool $completedP4 = false): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $user = $fixtures['user'];
    $user->update([
        'current_company_id' => $fixtures['company']->id,
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);

    grantCompanyPermissions($user, $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.corrections.override',
    ]);

    $vessel = makeCrewMovementVessel('Override Vessel', $fixtures['company']);
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
            'sequence' => 3,
            'status' => CrewPhaseStatus::Active,
            'actual_start_at' => $phase->actual_end_at,
        ]);
        $assignment->update(['current_phase_id' => $home->id]);
        $assignment->refresh();
        $phase->refresh();
    }

    return [
        'user' => $user,
        'company' => $fixtures['company'],
        'assignment' => $assignment,
        'phase' => $phase,
    ];
}

test('unauthenticated user cannot call override', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Unauth Vessel', $fixtures['company']);
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );

    $this->post(route('organization.crew-assignments.corrections.override', $assignment), [
        'crew_assignment_phase_id' => $assignment->currentPhase->id,
        'proposed_values' => ['remarks' => 'Unauthorized'],
        'reason' => 'Test reason',
    ])->assertRedirect(route('login'));
});

test('user without corrections.override cannot override directly', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $user = $fixtures['user'];
    $user->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($user, $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.corrections.request',
    ]);

    $vessel = makeCrewMovementVessel('No Override Vessel', $fixtures['company']);
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $assignment->currentPhase->id,
            'proposed_values' => ['remarks' => 'Forbidden attempt'],
            'reason' => 'Test reason',
        ])
        ->assertForbidden();
});

test('user with corrections.override but unconfirmed 2FA is blocked when enforcement is active', function () {
    enablePrivilegedTwoFactorEnforcement();

    try {
        $fixtures = makeCrewAssignmentFixtures();
        $user = $fixtures['user'];
        $user->update([
            'current_company_id' => $fixtures['company']->id,
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null,
        ]);
        grantCompanyPermissions($user, $fixtures['company'], [
            'crew_operations.assignments.view',
            'crew_operations.corrections.override',
        ]);

        $vessel = makeCrewMovementVessel('2FA Test Vessel', $fixtures['company']);
        $assignment = makeActiveOnVesselAssignment(
            $fixtures['company'],
            $fixtures['employee'],
            $fixtures['rank'],
            $vessel,
        );

        $this->actingAs($user)
            ->withSession(['current_company_id' => $fixtures['company']->id])
            ->post(route('organization.crew-assignments.corrections.override', $assignment), [
                'crew_assignment_phase_id' => $assignment->currentPhase->id,
                'proposed_values' => ['remarks' => 'Blocked without 2FA'],
                'reason' => 'Testing 2FA enforcement',
            ])
            ->assertRedirect(route('security.edit'));
    } finally {
        disablePrivilegedTwoFactorEnforcement();
    }
});

test('authorized user with 2FA applies direct correction override immediately without email', function () {
    Mail::fake();

    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOverrideTestFixtures();

    $newStart = $phase->actual_start_at->copy()->addDays(2)->timezone($company->timezone)->format('Y-m-d H:i');

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $phase->id,
            'proposed_values' => [
                'actual_start_at' => $newStart,
                'remarks' => 'Direct override by authorized manager',
            ],
            'reason' => 'Immediate correction required for port clearance',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $response->assertSessionHas('success', 'Movement correction applied successfully.');

    // Assert Correction created as Approved directly
    $correction = CrewMovementCorrection::query()
        ->where('crew_assignment_id', $assignment->id)
        ->where('crew_assignment_phase_id', $phase->id)
        ->first();

    expect($correction)->not->toBeNull()
        ->and($correction->status)->toBe(CrewMovementCorrectionStatus::Approved)
        ->and($correction->requested_by)->toBe($user->id)
        ->and($correction->decided_by)->toBe($user->id)
        ->and($correction->requested_at)->not->toBeNull()
        ->and($correction->decided_at)->not->toBeNull()
        ->and($correction->reason)->toBe('Immediate correction required for port clearance')
        ->and($correction->decision_notes)->toBe('Applied via direct correction override.')
        ->and($correction->applied_values)->toBeArray()
        ->and($correction->applied_values)->toHaveKey('actual_start_at')
        ->and($correction->applied_values)->toHaveKey('remarks');

    // Assert phase updated on database
    $phase->refresh();
    expect($phase->remarks)->toBe('Direct override by authorized manager')
        ->and($phase->actual_start_at->timezone($company->timezone)->format('Y-m-d H:i'))->toBe($newStart);

    // No notification sent for self-override
    Mail::assertNothingSent();

    // Activity log recorded
    $activity = DB::table('activity_log')
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('description', 'Crew movement correction override applied')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($user->id);
});

test('override is blocked when target phase already has a pending correction', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $phase] = makeOverrideTestFixtures();

    // Create existing pending correction on phase
    app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $user,
        ['remarks' => 'Pending note'],
        'Pending reason',
    );

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $phase->id,
            'proposed_values' => ['remarks' => 'Override attempt'],
            'reason' => 'Should conflict',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $response->assertSessionHasErrors('correction');

    $errors = session('errors')->get('correction');
    expect($errors[0])->toContain('already exists for this phase');
});

test('cross-company isolation prevents override on another company assignment', function () {
    ['user' => $userA, 'company' => $companyA] = makeOverrideTestFixtures();
    ['company' => $companyB, 'assignment' => $assignmentB, 'phase' => $phaseB] = makeOverrideTestFixtures();

    $this->actingAs($userA)
        ->withSession(['current_company_id' => $companyA->id])
        ->post(route('organization.crew-assignments.corrections.override', $assignmentB), [
            'crew_assignment_phase_id' => $phaseB->id,
            'proposed_values' => ['remarks' => 'Cross-company override'],
            'reason' => 'Malicious test',
        ])
        ->assertNotFound();
});

test('override validates accommodation chronology: hotel check-in before new arrival is rejected', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures();

    $base = now()->startOfDay();
    $p4->update(['sequence' => 2, 'actual_start_at' => $base->copy()->subDays(5)]);
    $p2a = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => $base->copy()->subDays(10),
        'actual_end_at' => $base->copy()->subDays(5),
    ]);

    $hotel = Hotel::query()->create([
        'company_id' => $company->id,
        'name' => 'Pre-Join Hotel',
        'is_active' => true,
    ]);
    $roomType = RoomType::query()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Standard Room',
        'is_active' => true,
    ]);

    // Hotel stay check-in is at subDays(9)
    CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => $base->copy()->subDays(9)->toDateString(),
        'check_out_date' => $base->copy()->subDays(7)->toDateString(),
        'started_from_phase_id' => $p2a->id,
    ]);

    // Attempt to correct P2A arrival to subDays(8), which is AFTER the hotel check-in of subDays(9)
    $illegalArrival = $base->copy()->subDays(8)->timezone($company->timezone)->format('Y-m-d H:i');

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $p2a->id,
            'proposed_values' => ['actual_start_at' => $illegalArrival],
            'reason' => 'Adjust arrival date',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $response->assertSessionHasErrors('correction');
    $errors = session('errors')->get('correction');
    expect($errors[0])->toContain('Hotel check-in cannot be before the actual arrival date');
});

test('override validates accommodation chronology: hotel checkout after new P4 join is rejected', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures();

    $hotel = Hotel::query()->create([
        'company_id' => $company->id,
        'name' => 'Transit Hotel',
        'is_active' => true,
    ]);
    $roomType = RoomType::query()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Transit Room',
        'is_active' => true,
    ]);

    $p4->update(['actual_start_at' => now()->subDays(3)]);

    CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->subDays(6)->toDateString(),
        'check_out_date' => now()->subDays(4)->toDateString(),
        'started_from_phase_id' => null,
    ]);

    // Attempt to correct P4 join to subDays(5), which is BEFORE the hotel checkout of subDays(4)
    $illegalJoin = now()->subDays(5)->timezone($company->timezone)->format('Y-m-d H:i');

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $p4->id,
            'proposed_values' => ['actual_start_at' => $illegalJoin],
            'reason' => 'Move join earlier',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $response->assertSessionHasErrors('correction');
    $errors = session('errors')->get('correction');
    expect($errors[0])->toContain('Hotel check-out cannot be after the actual vessel join date');
});

test('override on P4 rank recalculates Tour of Duty when planned_signoff_source is TourOfDuty', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures();

    $assignment->rank->update(['max_tour_of_duty_days' => 90]);

    $newRank = Rank::query()->create([
        'name' => 'Rank With 60 Day Tour',
        'is_active' => true,
        'max_tour_of_duty_days' => 60,
    ]);

    $assignment->update([
        'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
        'planned_signoff_at' => $p4->actual_start_at->copy()->addDays(90),
    ]);
    $p4->update(['planned_end_at' => $assignment->planned_signoff_at]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $p4->id,
            'proposed_values' => [
                'rank_id' => (string) $newRank->id,
            ],
            'reason' => 'Rank reclassification to shorter tour',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh();
    $p4->refresh();

    expect($assignment->rank_id)->toBe($newRank->id)
        ->and($assignment->planned_signoff_at->toDateString())
        ->toBe($p4->actual_start_at->copy()->addDays(60)->toDateString())
        ->and($p4->planned_end_at->toDateString())
        ->toBe($assignment->planned_signoff_at->toDateString());
});

test('override on P4 rank rejects new rank without tour rule when source is TourOfDuty', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures();

    $newRankNoRule = Rank::query()->create([
        'name' => 'Rank Without Tour',
        'is_active' => true,
        'max_tour_of_duty_days' => null,
    ]);

    $assignment->update([
        'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
        'planned_signoff_at' => $p4->actual_start_at->copy()->addDays(90),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $p4->id,
            'proposed_values' => [
                'rank_id' => (string) $newRankNoRule->id,
            ],
            'reason' => 'Change rank to one without tour',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $response->assertSessionHasErrors('correction');
    $errors = session('errors')->get('correction');
    expect($errors[0])->toContain('does not have a tour of duty configured');
});

test('override on P4 rank preserves planned signoff when source is manual override', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures();

    $newRank = Rank::query()->create([
        'name' => 'Rank With 45 Day Manual Tour',
        'is_active' => true,
        'max_tour_of_duty_days' => 60,
    ]);
    $fixedSignoff = now()->addDays(45);

    $assignment->update([
        'planned_signoff_source' => CrewPlannedSignoffSource::ManualOverride,
        'planned_signoff_at' => $fixedSignoff,
    ]);
    $p4->update(['planned_end_at' => $fixedSignoff]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $p4->id,
            'proposed_values' => [
                'rank_id' => (string) $newRank->id,
            ],
            'reason' => 'Update rank preserving manual plan',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh();
    $p4->refresh();

    expect($assignment->rank_id)->toBe($newRank->id)
        ->and($assignment->planned_signoff_at->toDateString())->toBe($fixedSignoff->toDateString())
        ->and($p4->planned_end_at->toDateString())->toBe($fixedSignoff->toDateString());
});

test('override validates accommodation chronology: post-signoff hotel check-in before actual disembarkation is rejected', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures(completedP4: true);

    $home = $assignment->phases()->where('phase_code', CrewPhaseCode::HomeRedeploy)->firstOrFail();
    $disembarkDate = $p4->actual_end_at->copy();
    $home->update([
        'actual_start_at' => $disembarkDate->copy()->addDays(5),
    ]);

    $hotel = Hotel::query()->create([
        'company_id' => $company->id,
        'name' => 'Post-Signoff Hotel A',
        'is_active' => true,
    ]);
    $roomType = RoomType::query()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Transit Room A',
        'is_active' => true,
    ]);

    // Hotel stay check-in is at day 31, checkout at day 33
    CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => $disembarkDate->copy()->addDay()->toDateString(),
        'check_out_date' => $disembarkDate->copy()->addDays(3)->toDateString(),
        'started_from_phase_id' => null,
    ]);

    // Attempt to correct P4 disembarkation (actual_end_at) to day 32 (which is AFTER hotel check-in on day 31)
    $illegalDisembarkation = $disembarkDate->copy()->addDays(2)->timezone($company->timezone)->format('Y-m-d H:i');

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $p4->id,
            'proposed_values' => ['actual_end_at' => $illegalDisembarkation],
            'reason' => 'Delay disembarkation past hotel check-in',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $response->assertSessionHasErrors('correction');
    $errors = session('errors')->get('correction');
    expect($errors[0])->toContain('Hotel check-in cannot be before the actual disembarkation date');
});

test('override validates accommodation chronology: post-signoff hotel checkout after actual return-home date is rejected', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures(completedP4: true);

    $home = $assignment->phases()->where('phase_code', CrewPhaseCode::HomeRedeploy)->firstOrFail();
    $disembarkDate = $p4->actual_end_at->copy();
    $home->update([
        'actual_start_at' => $disembarkDate->copy()->addDays(5),
    ]);

    $hotel = Hotel::query()->create([
        'company_id' => $company->id,
        'name' => 'Post-Signoff Hotel B',
        'is_active' => true,
    ]);
    $roomType = RoomType::query()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Transit Room B',
        'is_active' => true,
    ]);

    // Hotel stay checkout is at day 33 (disembark + 3 days)
    CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => $disembarkDate->copy()->addDay()->toDateString(),
        'check_out_date' => $disembarkDate->copy()->addDays(3)->toDateString(),
    ]);

    // Propose moving HomeRedeploy actual_start_at to day 32 (disembark + 2 days), which is BEFORE hotel checkout (day 33)
    $illegalReturnHome = $disembarkDate->copy()->addDays(2)->timezone($company->timezone)->format('Y-m-d H:i');

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $home->id,
            'proposed_values' => ['actual_start_at' => $illegalReturnHome],
            'reason' => 'Advance return home date before hotel checkout',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $response->assertSessionHasErrors('correction');
    $errors = session('errors')->get('correction');
    expect($errors[0])->toContain('Hotel check-out cannot be after the actual return-home date');
});

test('override succeeds when accommodation chronology boundaries are satisfied', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures();

    $base = now()->startOfDay();
    $p4->update(['sequence' => 2, 'actual_start_at' => $base->copy()->subDays(5)]);
    $p2a = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => $base->copy()->subDays(12),
        'actual_end_at' => $base->copy()->subDays(5),
    ]);

    $hotel = Hotel::query()->create([
        'company_id' => $company->id,
        'name' => 'Valid Pre-Join Hotel',
        'is_active' => true,
    ]);
    $roomType = RoomType::query()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Standard Room',
        'is_active' => true,
    ]);

    CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => $base->copy()->subDays(9)->toDateString(),
        'check_out_date' => $base->copy()->subDays(7)->toDateString(),
        'started_from_phase_id' => $p2a->id,
    ]);

    // Propose P2A arrival to subDays(10), which is BEFORE hotel check-in of subDays(9). Valid.
    $validArrival = $base->copy()->subDays(10)->timezone($company->timezone)->format('Y-m-d H:i');

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $p2a->id,
            'proposed_values' => ['actual_start_at' => $validArrival],
            'reason' => 'Legitimate adjustment within stay boundaries',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $response->assertSessionHas('success');

    $p2a->refresh();
    expect($p2a->actual_start_at->timezone($company->timezone)->format('Y-m-d H:i'))->toBe($validArrival);
});

test('override succeeds without accommodation stays present on assignment', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures();

    expect(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);

    $newStart = $p4->actual_start_at->copy()->addDay()->timezone($company->timezone)->format('Y-m-d H:i');

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $p4->id,
            'proposed_values' => ['actual_start_at' => $newStart],
            'reason' => 'Valid shift without hotel accommodation',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $response->assertSessionHas('success');

    $p4->refresh();
    expect($p4->actual_start_at->timezone($company->timezone)->format('Y-m-d H:i'))->toBe($newStart);
});

test('approval of pending correction fails revalidation if accommodation conflicts were introduced while pending', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.corrections.approve',
    ]);

    $requester = makeCrewAssignmentFixtures()['user'];
    grantCompanyPermissions($requester, $company, [
        'crew_operations.assignments.view',
        'crew_operations.corrections.request',
    ]);

    $base = now()->startOfDay();
    $p4->update(['actual_start_at' => $base->copy()->subDays(3)]);

    $proposedJoin = $base->copy()->subDays(5)->timezone($company->timezone)->format('Y-m-d H:i');

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $p4,
        $requester,
        ['actual_start_at' => $proposedJoin],
        'Request move earlier',
    );

    expect($correction->status)->toBe(CrewMovementCorrectionStatus::Pending);

    // While pending, an accommodation stay is recorded with check_out_date at subDays(4) (AFTER proposed join at subDays(5))
    $hotel = Hotel::query()->create([
        'company_id' => $company->id,
        'name' => 'Late Hotel',
        'is_active' => true,
    ]);
    $roomType = RoomType::query()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Late Room',
        'is_active' => true,
    ]);
    CrewAccommodationStay::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => $base->copy()->subDays(6)->toDateString(),
        'check_out_date' => $base->copy()->subDays(4)->toDateString(),
        'started_from_phase_id' => null,
    ]);

    try {
        app(ApproveCrewMovementCorrection::class)->handle(
            $correction,
            $user,
            $company->id,
            'Attempt approval',
        );
        $this->fail('Expected CrewMovementException was not thrown.');
    } catch (CrewMovementException $e) {
        expect($e->errorCode)->toBe('check_out_after_join');
    }

    $correction->refresh();
    $p4->refresh();
    expect($correction->status)->toBe(CrewMovementCorrectionStatus::Pending)
        ->and($correction->decided_at)->toBeNull()
        ->and($p4->actual_start_at->timezone($company->timezone)->format('Y-m-d H:i'))
        ->not->toBe($proposedJoin);
});

test('override rolls back all database mutations atomically when downstream pipeline fails', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures();

    $originalRemarks = 'Original P4 Remarks';
    $p4->update(['remarks' => $originalRemarks]);
    $originalRankId = $assignment->rank_id;
    $originalPhaseCount = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->count();
    $originalCorrectionsCount = CrewMovementCorrection::query()->where('crew_assignment_id', $assignment->id)->count();

    $this->mock(CrewAssignmentInvariantGuard::class, function ($mock) {
        $mock->shouldReceive('assertValid')
            ->andThrow(new RuntimeException('Downstream invariant validation failure'));
    });

    $proposedStart = $p4->actual_start_at->copy()->addDay()->timezone($company->timezone)->format('Y-m-d H:i');

    try {
        app(OverrideCrewMovementCorrection::class)->handle(
            $assignment,
            $p4,
            $user,
            $company->id,
            [
                'actual_start_at' => $proposedStart,
                'remarks' => 'Mutated remarks',
            ],
            'Atomic test failure',
        );
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Downstream invariant validation failure');
    }

    $assignment->refresh();
    $p4->refresh();

    expect($p4->remarks)->toBe($originalRemarks)
        ->and($assignment->rank_id)->toBe($originalRankId)
        ->and(CrewMovementCorrection::query()->where('crew_assignment_id', $assignment->id)->count())->toBe($originalCorrectionsCount)
        ->and(CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->count())->toBe($originalPhaseCount);
});

test('override on P4 rank preserves planned signoff and updates tour of duty days when source is ExistingPlan', function () {
    ['user' => $user, 'company' => $company, 'assignment' => $assignment, 'phase' => $p4] = makeOverrideTestFixtures();

    $newRank = Rank::query()->create([
        'name' => 'Rank With 75 Day Existing Plan Tour',
        'is_active' => true,
        'max_tour_of_duty_days' => 75,
    ]);
    $existingSignoff = now()->addDays(50);

    $assignment->update([
        'planned_signoff_source' => CrewPlannedSignoffSource::ExistingPlan,
        'planned_signoff_at' => $existingSignoff,
        'tour_of_duty_days' => 90,
    ]);
    $p4->update(['planned_end_at' => $existingSignoff]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-assignments.corrections.override', $assignment), [
            'crew_assignment_phase_id' => $p4->id,
            'proposed_values' => [
                'rank_id' => (string) $newRank->id,
            ],
            'reason' => 'Update rank preserving existing plan',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh();
    $p4->refresh();

    expect($assignment->rank_id)->toBe($newRank->id)
        ->and($assignment->planned_signoff_source)->toBe(CrewPlannedSignoffSource::ExistingPlan)
        ->and($assignment->planned_signoff_at->toDateString())->toBe($existingSignoff->toDateString())
        ->and($p4->planned_end_at->toDateString())->toBe($existingSignoff->toDateString())
        ->and($assignment->tour_of_duty_days)->toBe(75);
});
