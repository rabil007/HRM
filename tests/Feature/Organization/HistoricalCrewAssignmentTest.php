<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewOperationalAlert;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewAssignmentNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function makeOtherCompany(): Company
{
    $country = Country::first() ?? Country::query()->create(['code' => 'OC', 'name' => 'Other Land', 'dial_code' => '+002', 'is_active' => true]);
    $currency = Currency::first() ?? Currency::query()->create(['code' => 'OC', 'name' => 'Other Cur', 'symbol' => '$', 'is_active' => true]);

    return Company::query()->create([
        'name' => 'Other Company',
        'slug' => 'other-company-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('authorized user with create_historical permission can preview historical assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ]);

    $response->assertOk()
        ->assertJsonPath('employee.id', $employee->id)
        ->assertJsonPath('vessel.id', $vessel->id)
        ->assertJsonPath('rank.id', $rank->id)
        ->assertJsonPath('sea_service.days', 188)
        ->assertJsonPath('sea_service.status', 'will_create')
        ->assertJsonPath('checks.0.passed', true);
});

test('user without create_historical permission receives 403 on preview and store', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create', // Live create only, not historical
    ]);
    $user->update(['current_company_id' => $company->id]);

    $payload = [
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'joined_vessel_at' => '2024-01-15',
        'disembarked_at' => '2024-07-20',
    ];

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), $payload)
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), $payload)
        ->assertForbidden();
});

test('employee from another company is rejected', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $otherCompany = makeOtherCompany();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create(['status' => 'active']);
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $otherEmployee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['employee_id']);
});

test('vessel from another company is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $otherCompany = makeOtherCompany();
    $otherVessel = makeCrewMovementVessel('Other Co Vessel', $otherCompany);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $otherVessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['vessel_id']);
});

test('request cannot inject another company_id and resulting assignment uses active company', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $otherCompany = makeOtherCompany();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'company_id' => $otherCompany->id, // Attempted injection
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.index'));

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->first();
    expect($assignment)->not->toBeNull()
        ->and($assignment->company_id)->toBe($company->id)
        ->and($assignment->company_id)->not->toBe($otherCompany->id);
});

test('future timestamps are rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $futureDate = now()->addMonths(3)->format('Y-m-d');
    $farFutureDate = now()->addMonths(6)->format('Y-m-d');

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => $futureDate,
            'disembarked_at' => $farFutureDate,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['joined_vessel_at']);
});

test('joined vessel on or after disembarked is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-07-20',
            'disembarked_at' => '2024-01-15',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['disembarked_at']);
});

test('optional chronology errors are rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Mobilisation is after vessel join
    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'mobilisation_at' => '2024-02-01',
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['mobilisation_at']);
});

test('absent optional phases are never fabricated and only completed P4 is persisted', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
            'remarks' => 'Only sea time provided',
        ])
        ->assertRedirect(route('organization.crew-assignments.index'));

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->first();
    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($assignment->source)->toBe('historical_manual')
        ->and($assignment->remarks)->toBe('Only sea time provided');

    // Must have EXACTLY 1 phase: P4
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->get();
    expect($phases)->toHaveCount(1)
        ->and($phases->first()->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($phases->first()->status)->toBe(CrewPhaseStatus::Completed)
        ->and($assignment->current_phase_id)->toBe($phases->first()->id);
});

test('historical record can be added when employee has an active operational assignment in the future', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Vessel A', $company);

    // Create an existing Active operational assignment in 2026
    $activeAssignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'assignment_no' => app(CrewAssignmentNumberGenerator::class)->next($company->id),
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
        'started_at' => CarbonImmutable::parse('2026-02-01 08:00:00'),
    ]);

    $activeP4 = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $activeAssignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => CarbonImmutable::parse('2026-02-01 08:00:00'),
    ]);
    $activeAssignment->update(['current_phase_id' => $activeP4->id]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Add historical 2024 assignment
    $response = $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.index'));

    // Check that active operational assignment remains 100% untouched
    $activeAssignmentFresh = $activeAssignment->fresh();
    expect($activeAssignmentFresh->status)->toBe(CrewAssignmentStatus::Active)
        ->and($activeAssignmentFresh->current_phase_id)->toBe($activeP4->id)
        ->and($activeP4->fresh()->status)->toBe(CrewPhaseStatus::Active);
});

test('overlapping existing historical assignment is blocked with detailed message', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    // Existing historical assignment: 2024-01-01 to 2024-06-30
    $existing = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'assignment_no' => 'CA-2024-000001',
        'status' => CrewAssignmentStatus::Completed,
        'source' => 'historical_manual',
        'started_at' => CarbonImmutable::parse('2024-01-01 00:00:00'),
        'closed_at' => CarbonImmutable::parse('2024-06-30 00:00:00'),
    ]);

    $p4 = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $existing->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::parse('2024-01-01 00:00:00'),
        'actual_end_at' => CarbonImmutable::parse('2024-06-30 00:00:00'),
    ]);
    $existing->update(['current_phase_id' => $p4->id]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Overlapping attempt: 2024-05-15 to 2024-08-10
    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-05-15',
            'disembarked_at' => '2024-08-10',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['overlap']);

    $error = $response->json('errors.overlap.0');
    expect($error)->toContain('CA-2024-000001');

    // Existing assignment remains unchanged
    expect($existing->fresh()->closed_at->toDateString())->toBe('2024-06-30');
});

test('historical P4 synchronizes sea service and links exact unlinked match without duplicating', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    // Pre-existing unlinked Sea Service record for the same dates and vessel
    $existingSeaService = EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-15',
        'end_date' => '2024-07-20',
        'total_days' => 188,
        'total_months' => 6,
        'crew_assignment_phase_id' => null, // Unlinked
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Preview notes will_link
    $previewResponse = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ]);

    $previewResponse->assertOk()
        ->assertJsonPath('sea_service.status', 'will_link')
        ->assertJsonPath('sea_service.existing_id', $existingSeaService->id);

    // Persist
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertRedirect(route('organization.crew-assignments.index'));

    // Verify existing record is linked rather than duplicated
    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->first();
    $p4Phase = $assignment->currentPhase;

    expect($existingSeaService->fresh()->crew_assignment_phase_id)->toBe($p4Phase->id);
});

test('historical creation does not trigger unintended operational side effects', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertRedirect(route('organization.crew-assignments.index'));

    // Check no operational alerts created
    expect(CrewOperationalAlert::query()->where('company_id', $company->id)->count())->toBe(0);

    // Check no hotel stays created
    expect(CrewAccommodationStay::query()->where('crew_assignment_id', CrewAssignment::query()->where('employee_id', $employee->id)->value('id'))->count())->toBe(0);

    // Check no planning records created
    expect(CrewPlanningAssignment::query()->where('employee_id', $employee->id)->count())->toBe(0);
});
