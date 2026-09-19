<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\Rank;
use App\Models\User;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use Inertia\Testing\AssertableInertia as Assert;

function makeCorrectionVisibilityFixtures(): array
{
    $fixtures = makeEmployeeVisibilityFixtures();
    $user = $fixtures['user'];
    $company = $fixtures['company'];
    $marineEmployee = $fixtures['marineEmployee'];
    $officeEmployee = $fixtures['officeEmployee'];
    $marineDept = $fixtures['marineDept'];
    $officeDept = $fixtures['officeDept'];

    $user->update([
        'current_company_id' => $company->id,
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $rank = Rank::query()->create([
        'name' => 'Visibility Rank '.uniqid(),
        'is_active' => true,
    ]);

    $vessel = makeCrewMovementVessel('Visibility Vessel', $company);

    $marineAssignment = makeActiveOnVesselAssignment($company, $marineEmployee, $rank, $vessel);
    $officeAssignment = makeActiveOnVesselAssignment($company, $officeEmployee, $rank, $vessel);

    // Restrict user to Marine department only
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    return compact(
        'user',
        'company',
        'marineDept',
        'officeDept',
        'marineEmployee',
        'officeEmployee',
        'marineAssignment',
        'officeAssignment',
        'rank',
        'vessel',
    );
}

test('restricted user can request correction for visible marine employee assignment, but gets 404 for hidden office employee assignment', function () {
    $f = makeCorrectionVisibilityFixtures();

    // Marine assignment request: allowed
    $responseMarine = $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->post(route('organization.crew-assignments.corrections.store', $f['marineAssignment']), [
            'crew_assignment_phase_id' => $f['marineAssignment']->current_phase_id,
            'proposed_values' => [
                'actual_start_at' => $f['marineAssignment']->currentPhase->actual_start_at->copy()->addHour()->toDateTimeString(),
            ],
            'reason' => 'Marine correction test',
        ]);

    expect($responseMarine->status())->toBeIn([200, 302]);
    $this->assertDatabaseHas('crew_movement_corrections', [
        'crew_assignment_id' => $f['marineAssignment']->id,
        'status' => CrewMovementCorrectionStatus::Pending->value,
    ]);

    // Office assignment request: denied with 404
    $responseOffice = $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->post(route('organization.crew-assignments.corrections.store', $f['officeAssignment']), [
            'crew_assignment_phase_id' => $f['officeAssignment']->current_phase_id,
            'proposed_values' => [
                'actual_start_at' => $f['officeAssignment']->currentPhase->actual_start_at->copy()->addHour()->toDateTimeString(),
            ],
            'reason' => 'Office correction test',
        ]);

    $responseOffice->assertNotFound();
});

test('restricted user with override permission cannot direct override movement for hidden office employee assignment', function () {
    $f = makeCorrectionVisibilityFixtures();

    $response = $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->post(route('organization.crew-assignments.corrections.override', $f['officeAssignment']), [
            'crew_assignment_phase_id' => $f['officeAssignment']->current_phase_id,
            'proposed_values' => [
                'actual_start_at' => $f['officeAssignment']->currentPhase->actual_start_at->copy()->addHour()->toDateTimeString(),
            ],
            'reason' => 'Privileged override attempt on hidden employee',
        ]);

    $response->assertNotFound();
});

test('correction index only shows corrections for visible employees and excludes hidden employees', function () {
    $f = makeCorrectionVisibilityFixtures();

    $requestService = app(RequestCrewMovementCorrection::class);

    $marineCorrection = $requestService->handle(
        $f['marineAssignment'],
        $f['marineAssignment']->currentPhase,
        $f['user'],
        ['actual_start_at' => $f['marineAssignment']->currentPhase->actual_start_at->copy()->addHour()->toDateTimeString()],
        'Marine correction',
    );

    $adminUser = User::factory()->create();
    grantCompanyPermissions($adminUser, $f['company'], [
        'crew_operations.assignments.view',
        'crew_operations.corrections.request',
    ], 'admin-role');

    $officeCorrection = $requestService->handle(
        $f['officeAssignment'],
        $f['officeAssignment']->currentPhase,
        $adminUser,
        ['actual_start_at' => $f['officeAssignment']->currentPhase->actual_start_at->copy()->addHour()->toDateTimeString()],
        'Office correction',
    );

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->get(route('organization.crew-movement-corrections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-movement-corrections/index')
            ->has('corrections', 1)
            ->where('corrections.0.id', $marineCorrection->id)
            ->where('corrections.0.assignment.employee.name', 'Marine Crew')
        );
});

test('correction search never returns corrections for hidden employees', function () {
    $f = makeCorrectionVisibilityFixtures();

    $requestService = app(RequestCrewMovementCorrection::class);

    $adminUser = User::factory()->create();
    grantCompanyPermissions($adminUser, $f['company'], [
        'crew_operations.assignments.view',
        'crew_operations.corrections.request',
    ], 'admin-role');

    $officeCorrection = $requestService->handle(
        $f['officeAssignment'],
        $f['officeAssignment']->currentPhase,
        $adminUser,
        ['actual_start_at' => $f['officeAssignment']->currentPhase->actual_start_at->copy()->addHour()->toDateTimeString()],
        'Office correction',
    );

    // Search specifically for the office employee's name
    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->get(route('organization.crew-movement-corrections.index', ['search' => 'Office Staff']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-movement-corrections/index')
            ->has('corrections', 0)
        );
});

test('correction status_counts and summary_counts do not count hidden employee corrections', function () {
    $f = makeCorrectionVisibilityFixtures();

    $requestService = app(RequestCrewMovementCorrection::class);

    // Create 1 Marine pending correction
    $marineCorrection = $requestService->handle(
        $f['marineAssignment'],
        $f['marineAssignment']->currentPhase,
        $f['user'],
        ['actual_start_at' => $f['marineAssignment']->currentPhase->actual_start_at->copy()->addHour()->toDateTimeString()],
        'Marine correction',
    );

    // Create 2 Office pending corrections
    $adminUser = User::factory()->create();
    grantCompanyPermissions($adminUser, $f['company'], [
        'crew_operations.assignments.view',
        'crew_operations.corrections.request',
    ], 'admin-role');

    $officeCorrection1 = $requestService->handle(
        $f['officeAssignment'],
        $f['officeAssignment']->currentPhase,
        $adminUser,
        ['actual_start_at' => $f['officeAssignment']->currentPhase->actual_start_at->copy()->addHour()->toDateTimeString()],
        'Office correction 1',
    );

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->get(route('organization.crew-movement-corrections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-movement-corrections/index')
            ->where('status_counts.all', 1)
            ->where('status_counts.pending', 1)
            ->where('summary_counts.pending', 1)
        );
});

test('show, approve, reject, and cancel deny access with 404 when correction belongs to a hidden employee', function () {
    $f = makeCorrectionVisibilityFixtures();

    $requestService = app(RequestCrewMovementCorrection::class);

    $adminUser = User::factory()->create();
    grantCompanyPermissions($adminUser, $f['company'], [
        'crew_operations.assignments.view',
        'crew_operations.corrections.request',
    ], 'admin-role');

    $officeCorrection = $requestService->handle(
        $f['officeAssignment'],
        $f['officeAssignment']->currentPhase,
        $adminUser,
        ['actual_start_at' => $f['officeAssignment']->currentPhase->actual_start_at->copy()->addHour()->toDateTimeString()],
        'Office correction',
    );

    // Show
    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->get(route('organization.crew-movement-corrections.show', $officeCorrection))
        ->assertNotFound();

    // Approve
    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->post(route('organization.crew-movement-corrections.approve', $officeCorrection), [
            'decision_notes' => 'Approve attempt',
        ])
        ->assertNotFound();

    // Reject
    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->post(route('organization.crew-movement-corrections.reject', $officeCorrection), [
            'decision_notes' => 'Reject attempt',
        ])
        ->assertNotFound();

    // Cancel
    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->post(route('organization.crew-movement-corrections.cancel', $officeCorrection), [
            'decision_notes' => 'Cancel attempt',
        ])
        ->assertNotFound();
});

test('foreign company correction access is denied with 404', function () {
    $f = makeCorrectionVisibilityFixtures();

    $foreignFixtures = makeCrewAssignmentFixtures();
    $otherCompany = $foreignFixtures['company'];
    $foreignRank = Rank::query()->create(['name' => 'Foreign Rank '.uniqid(), 'is_active' => true]);
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel', $otherCompany);
    $foreignAssignment = makeActiveOnVesselAssignment($otherCompany, $foreignFixtures['employee'], $foreignRank, $foreignVessel);

    $requestService = app(RequestCrewMovementCorrection::class);
    $foreignCorrection = $requestService->handle(
        $foreignAssignment,
        $foreignAssignment->currentPhase,
        $f['user'],
        ['actual_start_at' => $foreignAssignment->currentPhase->actual_start_at->copy()->addHour()->toDateTimeString()],
        'Foreign correction',
    );

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->get(route('organization.crew-movement-corrections.show', $foreignCorrection))
        ->assertNotFound();
});
