<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\Employee;
use Carbon\Carbon;

function startingStageStorePermissions(): array
{
    return [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ];
}

test('starting stage selection store paths create draft, p0, and p2a assignments', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], startingStageStorePermissions());
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    $user = $fixtures['user'];
    $company = $fixtures['company'];
    $employee = $fixtures['employee'];
    $rank = $fixtures['rank'];
    $vessel = makeCrewMovementVessel('Starting Stage Vessel', $company);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
        ])
        ->assertRedirect();

    $draft = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();
    expect($draft?->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($draft?->started_at)->toBeNull();

    $employeeB = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'name' => 'P0 Starter',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employeeB->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-20',
            'already_arrived' => false,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect();

    $p0 = CrewAssignment::query()->where('employee_id', $employeeB->id)->with('currentPhase')->first();
    expect($p0?->status)->toBe(CrewAssignmentStatus::Active)
        ->and($p0?->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($p0?->currentPhase?->status)->toBe(CrewPhaseStatus::Active);

    $employeeC = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'name' => 'P2A Starter',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employeeC->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'planned_join_at' => '2026-10-20',
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect();

    $p2a = CrewAssignment::query()->where('employee_id', $employeeC->id)->with(['phases', 'currentPhase'])->first();
    expect($p2a?->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($p2a?->phases)->toHaveCount(1);
});

test('already arrived starting stage rejects missing actual arrival date', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], startingStageStorePermissions());
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    $vessel = makeCrewMovementVessel('Missing Arrival Vessel', $fixtures['company']);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $fixtures['employee']->id,
            'position_id' => $fixtures['rank']->id,
            'vessel_id' => $vessel->id,
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertSessionHasErrors(['planned_arrival_at']);

    expect(CrewAssignment::query()->where('company_id', $fixtures['company']->id)->count())->toBe(0);
});
