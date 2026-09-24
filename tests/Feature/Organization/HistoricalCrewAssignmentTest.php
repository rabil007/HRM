<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Client;
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
use App\Models\Rank;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewAssignmentNumberGenerator;
use App\Support\CrewMovements\CrewMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

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

test('on vessel plus disembarked without home creates active P5 with completed P4 and sea service', function () {
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
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->closed_at)->toBeNull()
        ->and($assignment->source)->toBe('historical_manual')
        ->and($assignment->remarks)->toBe('Only sea time provided');

    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();
    expect($phases)->toHaveCount(2)
        ->and($phases[0]->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($phases[0]->status)->toBe(CrewPhaseStatus::Completed)
        ->and($phases[0]->actual_end_at->toDateString())->toBe('2024-07-20')
        ->and($phases[1]->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($phases[1]->status)->toBe(CrewPhaseStatus::Active)
        ->and($phases[1]->actual_end_at)->toBeNull()
        ->and($assignment->current_phase_id)->toBe($phases[1]->id);

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('completed historical record can be added when employee has an active operational assignment later', function () {
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

    // Completed historical 2024 assignment (Home closes the bootstrap)
    $response = $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
            'travel_home_at' => '2024-07-20',
        ]);

    $response->assertRedirect(route('organization.crew-assignments.index'));

    // Check that active operational assignment remains 100% untouched
    $activeAssignmentFresh = $activeAssignment->fresh();
    expect($activeAssignmentFresh->status)->toBe(CrewAssignmentStatus::Active)
        ->and($activeAssignmentFresh->current_phase_id)->toBe($activeP4->id)
        ->and($activeP4->fresh()->status)->toBe(CrewPhaseStatus::Active);

    $historical = CrewAssignment::query()
        ->where('employee_id', $employee->id)
        ->where('source', 'historical_manual')
        ->first();
    expect($historical)->not->toBeNull()
        ->and($historical->status)->toBe(CrewAssignmentStatus::Completed);
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
    $p4Phase = CrewAssignmentPhase::query()
        ->where('crew_assignment_id', $assignment->id)
        ->where('phase_code', CrewPhaseCode::OnVessel)
        ->first();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($p4Phase)->not->toBeNull()
        ->and($existingSeaService->fresh()->crew_assignment_phase_id)->toBe($p4Phase->id);
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

test('preview response matches the canonical contract exactly', function () {
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
            'remarks' => 'Inspection crew',
        ]);

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveKeys([
        'valid',
        'employee',
        'vessel',
        'rank',
        'client',
        'summary',
        'timeline',
        'checks',
        'warnings',
        'sea_service',
    ]);

    expect($data['employee'])->toHaveKeys(['id', 'name', 'employee_no']);
    expect($data['vessel'])->toHaveKeys(['id', 'name']);
    expect($data['rank'])->toHaveKeys(['id', 'name']);
    expect($data['summary'])->toEqual([
        'joined_vessel_at' => '15 Jan 2024',
        'disembarked_at' => '20 Jul 2024',
        'sea_service_days' => 188,
        'remarks' => 'Inspection crew',
        'assignment_status' => 'active',
        'is_open' => true,
    ]);

    expect($data['timeline'])->toHaveCount(2);
    expect($data['timeline'][0])->toEqual([
        'phase_code' => 'p4',
        'phase_label' => 'On Vessel',
        'start' => '15 Jan 2024',
        'end' => '20 Jul 2024',
        'end_display' => '20 Jul 2024',
        'is_open' => false,
        'duration_days' => 188,
    ]);
    expect($data['timeline'][1])->toEqual([
        'phase_code' => 'p5',
        'phase_label' => 'Demobilisation Standby',
        'start' => '20 Jul 2024',
        'end' => null,
        'end_display' => 'Current',
        'is_open' => true,
        'duration_days' => null,
    ]);

    expect($data['checks'])->toBeArray()->not->toBeEmpty();
    foreach ($data['checks'] as $check) {
        expect($check)->toHaveKeys(['code', 'passed', 'message']);
        expect($check['code'])->toBeString()->not->toBeEmpty();
        expect($check['passed'])->toBeBool();
        expect($check['message'])->toBeString()->not->toBeEmpty();
    }

    expect($data['sea_service'])->toHaveKeys([
        'status',
        'days',
        'months',
        'start_date',
        'end_date',
        'vessel_id',
        'vessel_name',
        'existing_id',
        'message',
    ]);
    expect($data['sea_service']['status'])->toBe('will_create');
    expect($data['sea_service']['days'])->toBe(188);
    expect($data['sea_service']['months'])->toBe(6);
    expect($data['sea_service']['start_date'])->toBe('2024-01-15');
    expect($data['sea_service']['end_date'])->toBe('2024-07-20');
});

test('completed historical history is allowed for active inactive terminated and on_leave employees', function (string $status) {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);
    $employee = Employee::factory()->forCompany($company)->create(['status' => $status]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $payload = [
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'joined_vessel_at' => '2024-01-15',
        'disembarked_at' => '2024-07-20',
        'travel_home_at' => '2024-07-23',
    ];

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), $payload)
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('summary.assignment_status', 'completed');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), $payload)
        ->assertRedirect(route('organization.crew-assignments.index'));

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->first();
    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($assignment->closed_at)->not->toBeNull();
})->with(['active', 'inactive', 'terminated', 'on_leave']);

test('open historical bootstrap is blocked for inactive terminated and on_leave employees', function (string $status) {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);
    $employee = Employee::factory()->forCompany($company)->create(['status' => $status]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
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
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignment']);

    expect($this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), $payload)
        ->json('errors.assignment.0'))
        ->toContain('not currently Active');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), $payload)
        ->assertSessionHasErrors(['assignment']);

    expect(CrewAssignment::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
})->with(['inactive', 'terminated', 'on_leave']);

test('open historical bootstrap is allowed for active employees', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $payload = [
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'joined_vessel_at' => '2024-01-15',
    ];

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), $payload)
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('summary.assignment_status', 'active')
        ->assertJsonPath('inferred_state.phase_code', 'p4');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), $payload)
        ->assertRedirect(route('organization.crew-assignments.index'));

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->first();
    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($assignment->currentPhase?->actual_end_at)->toBeNull();
});

test('historical rank snapshot persists independently of employee current rank', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $currentRank = Rank::query()->create(['name' => 'Current Rank '.Str::random(5), 'is_active' => true]);
    $historicalRank = Rank::query()->create(['name' => 'Historical Rank '.Str::random(5), 'is_active' => true]);
    $employee->update(['rank_id' => $currentRank->id, 'status' => 'active']);
    $vessel = makeCrewMovementVessel('Rank Snapshot Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $historicalRank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
            'travel_home_at' => '2024-07-23',
        ])
        ->assertRedirect(route('organization.crew-assignments.index'));

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->firstOrFail();
    $sea = EmployeeSeaService::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($assignment->rank_id)->toBe($historicalRank->id)
        ->and($sea->rank_id)->toBe($historicalRank->id)
        ->and($employee->fresh()->rank_id)->toBe($currentRank->id);
});

test('historical entry rejects soft-deleted employees', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);
    $employee->delete();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['employee_id']);
});

test('historical entry respects role-based employee visibility scope and rejects hidden department employees', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $officeEmployee] = makeEmployeeVisibilityFixtures();
    $rank = Rank::query()->create(['name' => 'Third Officer '.Str::random(5), 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    restrictUserToDepartments($user, $company, [$marineDept->id]);
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $officeEmployee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['employee_id']);
});

test('company timezone semantics preserve local calendar dates for Dubai midnight and 23:30', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
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
            'joined_vessel_at' => '2024-01-15 00:00:00',
            'disembarked_at' => '2024-07-20 23:30:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.index'));

    $seaService = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();
    expect($seaService)->not->toBeNull()
        ->and($seaService->start_date->toDateString())->toBe('2024-01-15')
        ->and($seaService->end_date->toDateString())->toBe('2024-07-20')
        ->and($seaService->total_days)->toBe(188);
});

test('phase correctness: join standby creates P2A and training creates P2B without fabricating missing phases', function () {
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
            'join_standby_at' => '2024-01-10',
            'training_started_at' => '2024-01-12',
            'training_ended_at' => '2024-01-14',
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertRedirect(route('organization.crew-assignments.index'));

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->first();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active);
    expect($phases)->toHaveCount(5);
    expect($phases[0]->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($phases[0]->status)->toBe(CrewPhaseStatus::Completed)
        ->and($phases[0]->sequence)->toBe(1);

    expect($phases[1]->phase_code)->toBe(CrewPhaseCode::Training)
        ->and($phases[1]->status)->toBe(CrewPhaseStatus::Completed)
        ->and($phases[1]->sequence)->toBe(2);

    expect($phases[2]->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($phases[2]->status)->toBe(CrewPhaseStatus::Completed)
        ->and($phases[2]->actual_start_at->toDateString())->toBe('2024-01-14');

    expect($phases[3]->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($phases[3]->status)->toBe(CrewPhaseStatus::Completed)
        ->and($phases[3]->sequence)->toBe(4);

    expect($phases[4]->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($phases[4]->status)->toBe(CrewPhaseStatus::Active)
        ->and($phases[4]->actual_end_at)->toBeNull();

    $trainingPhases = $phases->where('phase_code', CrewPhaseCode::Training);
    expect($trainingPhases)->toHaveCount(1)
        ->and($trainingPhases->first()->actual_start_at->toDateString())->toBe('2024-01-12')
        ->and($trainingPhases->first()->actual_end_at->toDateString())->toBe('2024-01-14');
});

test('phase correctness: full training loop P2A -> P2B -> P2A -> P4 persists ordered phases', function () {
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
            'join_standby_at' => '2024-01-10',
            'training_started_at' => '2024-01-12',
            'training_ended_at' => '2024-01-14',
            'joined_vessel_at' => '2024-01-16',
            'disembarked_at' => '2024-07-20',
            'travel_home_at' => '2024-07-22',
        ])
        ->assertRedirect(route('organization.crew-assignments.index'));

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->first();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed);
    expect($phases)->toHaveCount(6);
    expect($phases[0]->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($phases[0]->sequence)->toBe(1);
    expect($phases[1]->phase_code)->toBe(CrewPhaseCode::Training)
        ->and($phases[1]->sequence)->toBe(2);
    expect($phases[2]->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($phases[2]->sequence)->toBe(3)
        ->and($phases[2]->actual_start_at->toDateString())->toBe('2024-01-14');
    expect($phases[3]->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($phases[3]->sequence)->toBe(4);
    expect($phases[4]->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($phases[4]->sequence)->toBe(5)
        ->and($phases[4]->status)->toBe(CrewPhaseStatus::Completed);
    expect($phases[5]->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and($phases[5]->actual_start_at->toDateString())->toBe('2024-07-22')
        ->and($phases[5]->status)->toBe(CrewPhaseStatus::Completed);
});

test('phase correctness: demob standby P5 and travel home P6 persist ordered phases', function () {
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
            'travel_home_at' => '2024-07-22',
        ])
        ->assertRedirect(route('organization.crew-assignments.index'));

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->first();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed);
    expect($phases)->toHaveCount(3);
    expect($phases[0]->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($phases[0]->sequence)->toBe(1);
    expect($phases[1]->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($phases[1]->sequence)->toBe(2)
        ->and($phases[1]->status)->toBe(CrewPhaseStatus::Completed);
    expect($phases[2]->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and($phases[2]->sequence)->toBe(3)
        ->and($phases[2]->status)->toBe(CrewPhaseStatus::Completed);
});

test('chronology validation enforces strict ordering between all supplied movement phases', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // 1. Training ended before started
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'training_started_at' => '2024-01-14',
            'training_ended_at' => '2024-01-12',
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['training_end_at']);

    // 2. Legacy post-training standby input is prohibited
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'training_started_at' => '2024-01-10',
            'training_ended_at' => '2024-01-14',
            'post_training_join_standby_at' => '2024-01-13',
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['post_training_join_standby_at']);

    // 3. Joined vessel before standby
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'join_standby_at' => '2024-01-16',
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['joined_vessel_at']);

    // 4. Legacy demob standby input is prohibited
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
            'demob_standby_at' => '2024-07-19',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['demob_standby_at']);

    // 5. Travel home before disembarked
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-21',
            'travel_home_at' => '2024-07-20',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['travel_home_at']);

    // 6. Legacy assignment closed input is prohibited
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
            'travel_home_at' => '2024-07-22',
            'assignment_closed_at' => '2024-07-21',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assignment_closed_at']);
});

test('sea service exact match with conflicting rank blocks and does not rewrite existing HR history', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);
    $otherRank = Rank::query()->create(['name' => 'Second Officer '.Str::random(5), 'is_active' => true]);

    $existingSeaService = EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $otherRank->id,
        'start_date' => '2024-01-15',
        'end_date' => '2024-07-20',
        'total_days' => 188,
        'total_months' => 6,
        'crew_assignment_phase_id' => null,
    ]);

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

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sea_service']);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertSessionHasErrors(['sea_service']);

    expect($existingSeaService->fresh()->rank_id)->toBe($otherRank->id)
        ->and($existingSeaService->fresh()->crew_assignment_phase_id)->toBeNull();
});

test('sea service exact match with conflicting client blocks', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);
    $clientA = Client::factory()->create(['is_active' => true]);
    $clientB = Client::factory()->create(['is_active' => true]);

    EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'client_id' => $clientA->id,
        'start_date' => '2024-01-15',
        'end_date' => '2024-07-20',
        'total_days' => 188,
        'total_months' => 6,
        'crew_assignment_phase_id' => null,
    ]);

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
            'client_id' => $clientB->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sea_service']);
});

test('sea service inclusive date boundary overlap is rejected as conflict', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-10',
        'total_days' => 10,
        'total_months' => 0,
        'crew_assignment_phase_id' => null,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Boundary touch: 2024-01-10 to 2024-01-20 (Jan 10 is shared, so 1-day overlap)
    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-10',
            'disembarked_at' => '2024-01-20',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sea_service']);
});

test('sea service adjacent dates without overlap are allowed', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);

    EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-10',
        'total_days' => 10,
        'total_months' => 0,
        'crew_assignment_phase_id' => null,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Adjacent non-overlap: 2024-01-11 to 2024-01-20
    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-11',
            'disembarked_at' => '2024-01-20',
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('sea_service.status', 'will_create');
});

test('historical creation logs activity with company_id, subject, and causer', function () {
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

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->first();

    $activity = Activity::query()
        ->where('event', 'historical_crew_assignment_created')
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->company_id)->toBe($company->id)
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->properties['company_id'])->toBe($company->id)
        ->and($activity->properties['employee_id'])->toBe($employee->id)
        ->and($activity->properties['assignment_id'])->toBe($assignment->id)
        ->and($activity->properties['source'])->toBe('historical_manual')
        ->and($activity->properties['historical_joined_vessel_at'])->toBe('2024-01-15')
        ->and($activity->properties['historical_disembarked_at'])->toBe('2024-07-20');
});

test('historical form options are exposed on index only when authorized and default employee list remains empty', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    // 1. Without create_historical permission
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('historical_form_options', null)
            ->where('form_options.employees', [])
        );

    // 2. With create_historical permission
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('historical_form_options.employees.0.id', $employee->id)
            ->where('form_options.employees', [])
        );
});

test('historical blank client stays null and does not auto-fill vessel current client', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $client = Client::factory()->create(['is_active' => true]);
    $vessel = makeCrewMovementVessel('Historical Vessel', $company);
    $vessel->update(['client_id' => $client->id]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertOk()
        ->assertJsonPath('client', null);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertRedirect(route('organization.crew-assignments.index'));

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();

    expect($assignment->client_id)->toBeNull()
        ->and((int) $vessel->fresh()->client_id)->toBe((int) $client->id);
});

test('historical client snapshot differs from vessel current client with warning and persists snapshot', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $historicalClient = Client::factory()->create(['name' => 'OLD MARINE '.Str::random(4), 'is_active' => true]);
    $currentClient = Client::factory()->create(['name' => 'NEW MARINE '.Str::random(4), 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Snapshot Vessel', $company, $currentClient);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $preview = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'client_id' => $historicalClient->id,
            'joined_vessel_at' => '2022-01-15',
            'disembarked_at' => '2022-07-20',
        ])
        ->assertOk();

    expect($preview->json('client.id'))->toBe($historicalClient->id);
    $warnings = collect($preview->json('warnings') ?? [])->implode(' ');
    expect($warnings)->toContain('OLD MARINE')
        ->and($warnings)->toContain('NEW MARINE')
        ->and($warnings)->toContain('historical Client snapshot will be preserved');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'client_id' => $historicalClient->id,
            'joined_vessel_at' => '2022-01-15',
            'disembarked_at' => '2022-07-20',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    $seaService = EmployeeSeaService::query()->where('employee_id', $employee->id)->firstOrFail();

    expect((int) $assignment->client_id)->toBe((int) $historicalClient->id)
        ->and((int) $seaService->client_id)->toBe((int) $historicalClient->id)
        ->and((int) $vessel->fresh()->client_id)->toBe((int) $currentClient->id);
});

test('multiple exact unlinked sea service matches block historical preview and create', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Ambiguous Sea Vessel', $company);

    $first = EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-06-30',
        'total_days' => 182,
        'total_months' => 5,
        'crew_assignment_phase_id' => null,
    ]);
    $second = EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-06-30',
        'total_days' => 182,
        'total_months' => 5,
        'crew_assignment_phase_id' => null,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $preview = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-01',
            'disembarked_at' => '2024-06-30',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sea_service']);

    $message = $preview->json('errors.sea_service.0');
    expect($message)->toContain('#'.$first->id)
        ->and($message)->toContain('#'.$second->id)
        ->and($message)->toContain('Multiple Sea Service records match');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-01',
            'disembarked_at' => '2024-06-30',
        ])
        ->assertSessionHasErrors(['sea_service']);

    expect(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('exact matching sea service already linked blocks historical entry', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Linked Sea Vessel', $company);

    $existing = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-HIST-LINK-1',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => '2023-01-01',
        'closed_at' => '2023-06-30',
        'source' => 'historical_manual',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $existing->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2023-01-01',
        'actual_end_at' => '2023-06-30',
        'started_by' => $user->id,
        'completed_by' => $user->id,
    ]);

    EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-06-30',
        'total_days' => 182,
        'total_months' => 5,
        'crew_assignment_phase_id' => $phase->id,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-01',
            'disembarked_at' => '2024-06-30',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sea_service']);
});

test('on vessel plus disembarked without home leaves active P5 open', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('P5 Open Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertOk()
        ->assertJsonPath('summary.is_open', true)
        ->assertJsonPath('summary.assignment_status', 'active')
        ->assertJsonPath('inferred_state.phase_code', 'p5');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->closed_at)->toBeNull()
        ->and($phases->pluck('phase_code')->all())->toBe([
            CrewPhaseCode::OnVessel,
            CrewPhaseCode::DemobStandby,
        ])
        ->and($phases[1]->actual_end_at)->toBeNull()
        ->and($phases[1]->status)->toBe(CrewPhaseStatus::Active);
});

test('disembarked with home later creates completed P5 then P6', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('P5 Home Vessel', $company);

    grantCompanyPermissions($user, $company, [
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
            'travel_home_at' => '2024-07-25',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($phases->pluck('phase_code')->all())->toBe([
            CrewPhaseCode::OnVessel,
            CrewPhaseCode::DemobStandby,
            CrewPhaseCode::HomeRedeploy,
        ])
        ->and($phases[1]->actual_end_at->toDateString())->toBe('2024-07-25')
        ->and($phases[2]->actual_start_at->toDateString())->toBe('2024-07-25')
        ->and($phases[2]->actual_end_at->toDateString())->toBe('2024-07-25');
});

test('disembarked and home at same timestamp creates direct P4 to P6 without positive-duration P5', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Direct Home Vessel', $company);

    grantCompanyPermissions($user, $company, [
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
            'travel_home_at' => '2024-07-20',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($phases->pluck('phase_code')->all())->toBe([
            CrewPhaseCode::OnVessel,
            CrewPhaseCode::HomeRedeploy,
        ])
        ->and($phases->contains(fn ($phase) => $phase->phase_code === CrewPhaseCode::DemobStandby))->toBeFalse()
        ->and($phases[1]->actual_start_at->equalTo($phases[1]->actual_end_at))->toBeTrue();
});

test('historical form options include inactive vessel rank and client while live options stay active-only', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $activeRank] = makeCrewAssignmentFixtures();

    $inactiveRank = Rank::query()->create(['name' => 'Inactive Rank Opt '.Str::random(5), 'is_active' => false]);
    $inactiveClient = Client::factory()->create(['name' => 'Inactive Client Opt '.Str::random(5), 'is_active' => false]);
    $inactiveVessel = makeCrewMovementVessel('Inactive Vessel Opt '.Str::random(5), $company, $inactiveClient);
    $inactiveVessel->update(['is_active' => false]);
    $activeVessel = makeCrewMovementVessel('Active Vessel Opt '.Str::random(5), $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('historical_form_options.vessels', fn (Assert $vessels) => $vessels
                ->where('0.id', fn ($id) => true)
                ->etc()
            )
            ->where('historical_form_options.vessels', function ($vessels) use ($inactiveVessel, $activeVessel) {
                $ids = collect($vessels)->pluck('id')->all();
                $inactive = collect($vessels)->firstWhere('id', $inactiveVessel->id);

                return in_array($inactiveVessel->id, $ids, true)
                    && in_array($activeVessel->id, $ids, true)
                    && $inactive !== null
                    && str_contains((string) $inactive['name'], 'Inactive')
                    && ($inactive['is_active'] ?? true) === false;
            })
            ->where('historical_form_options.ranks', function ($ranks) use ($inactiveRank, $activeRank) {
                $ids = collect($ranks)->pluck('id')->all();
                $inactive = collect($ranks)->firstWhere('id', $inactiveRank->id);

                return in_array($inactiveRank->id, $ids, true)
                    && in_array($activeRank->id, $ids, true)
                    && $inactive !== null
                    && str_contains((string) $inactive['name'], 'Inactive');
            })
            ->where('historical_form_options.clients', function ($clients) use ($inactiveClient) {
                $inactive = collect($clients)->firstWhere('id', $inactiveClient->id);

                return $inactive !== null
                    && str_contains((string) $inactive['name'], 'Inactive')
                    && ($inactive['is_active'] ?? true) === false;
            })
            ->where('form_options.vessels', function ($vessels) use ($inactiveVessel, $activeVessel) {
                $ids = collect($vessels)->pluck('id')->all();

                return ! in_array($inactiveVessel->id, $ids, true)
                    && in_array($activeVessel->id, $ids, true);
            })
            ->where('form_options.ranks', function ($ranks) use ($inactiveRank, $activeRank) {
                $ids = collect($ranks)->pluck('id')->all();

                return ! in_array($inactiveRank->id, $ids, true)
                    && in_array($activeRank->id, $ids, true);
            })
            ->where('form_options.clients', function ($clients) use ($inactiveClient) {
                $ids = collect($clients)->pluck('id')->all();

                return ! in_array($inactiveClient->id, $ids, true);
            })
        );
});

test('historical validation aliases training fields and surfaces sea service and overlap messages', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Alias Vessel', $company);
    $otherRank = Rank::query()->create(['name' => 'Master Alias '.Str::random(4), 'is_active' => true]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Chronology: training_end before training_start → aliased form keys
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-06-01',
            'disembarked_at' => '2024-08-01',
            'training_started_at' => '2024-05-20',
            'training_ended_at' => '2024-05-10',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['training_started_at', 'training_ended_at']);

    // Legacy demob field remains prohibited
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-06-01',
            'disembarked_at' => '2024-08-01',
            'post_signoff_standby_at' => '2024-07-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['post_signoff_standby_at']);

    // Persist a sea service conflict for overlap/conflict messaging
    EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $otherRank->id,
        'start_date' => '2023-01-01',
        'end_date' => '2023-06-30',
        'total_days' => 181,
        'total_months' => 6,
        'sort_order' => 0,
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2023-01-01',
            'disembarked_at' => '2023-06-30',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sea_service']);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2022-01-01',
            'disembarked_at' => '2022-06-30',
            'travel_home_at' => '2022-06-30',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2022-03-01',
            'disembarked_at' => '2022-08-01',
            'travel_home_at' => '2022-08-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['overlap']);
});

test('manual historical entry rejects legacy removed movement inputs', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Legacy Reject Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
            'arrival_at' => '2024-01-10',
            'ready_to_join_at' => '2024-01-14',
            'post_training_join_standby_at' => '2024-01-14',
            'demob_standby_at' => '2024-07-21',
            'assignment_closed_at' => '2024-07-23',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'arrival_at',
            'ready_to_join_at',
            'post_training_join_standby_at',
            'demob_standby_at',
            'assignment_closed_at',
        ]);
});

test('modern full lifecycle creates only P0 P2A P4 P5 P6 without P1 or P3', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Modern Lifecycle Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'mobilisation_at' => '2024-01-01',
            'join_standby_at' => '2024-01-05',
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
            'travel_home_at' => '2024-07-22',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()
        ->where('employee_id', $employee->id)
        ->where('source', 'historical_manual')
        ->firstOrFail();

    $phases = CrewAssignmentPhase::query()
        ->where('crew_assignment_id', $assignment->id)
        ->orderBy('sequence')
        ->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($phases->pluck('phase_code')->all())->toBe([
            CrewPhaseCode::PreMobilisation,
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::OnVessel,
            CrewPhaseCode::DemobStandby,
            CrewPhaseCode::HomeRedeploy,
        ])
        ->and($phases->contains(fn ($phase) => $phase->phase_code === CrewPhaseCode::TravelIn))->toBeFalse()
        ->and($phases->contains(fn ($phase) => $phase->phase_code === CrewPhaseCode::ReadyToJoin))->toBeFalse();
});

test('phase inference: pre-mobilisation only creates active P0', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('P0 Only Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'mobilisation_at' => '2024-01-01',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($phases)->toHaveCount(1)
        ->and($phases[0]->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($phases[0]->status)->toBe(CrewPhaseStatus::Active)
        ->and($phases[0]->actual_end_at)->toBeNull()
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('phase inference: pre-mobilisation plus join standby creates completed P0 and active P2A', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('P0 P2A Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'mobilisation_at' => '2024-01-01',
            'join_standby_at' => '2024-01-05',
        ])
        ->assertRedirect();

    $phases = CrewAssignmentPhase::query()
        ->where('crew_assignment_id', CrewAssignment::query()->where('employee_id', $employee->id)->value('id'))
        ->orderBy('sequence')
        ->get();

    expect($phases->pluck('phase_code')->all())->toBe([
        CrewPhaseCode::PreMobilisation,
        CrewPhaseCode::JoinStandby,
    ])
        ->and($phases[0]->status)->toBe(CrewPhaseStatus::Completed)
        ->and($phases[1]->status)->toBe(CrewPhaseStatus::Active)
        ->and($phases[1]->actual_end_at)->toBeNull();
});

test('phase inference: join standby plus training start creates active P2B', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('P2B Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'join_standby_at' => '2024-01-05',
            'training_started_at' => '2024-01-10',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($phases->pluck('phase_code')->all())->toBe([
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::Training,
        ])
        ->and($phases[1]->status)->toBe(CrewPhaseStatus::Active)
        ->and($phases[1]->actual_end_at)->toBeNull();
});

test('phase inference: training start plus training end creates completed P2B and active P2A', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Training End Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'training_started_at' => '2024-01-10',
            'training_ended_at' => '2024-01-14',
        ])
        ->assertRedirect();

    $phases = CrewAssignmentPhase::query()
        ->where('crew_assignment_id', CrewAssignment::query()->where('employee_id', $employee->id)->value('id'))
        ->orderBy('sequence')
        ->get();

    expect($phases->pluck('phase_code')->all())->toBe([
        CrewPhaseCode::Training,
        CrewPhaseCode::JoinStandby,
    ])
        ->and($phases[0]->status)->toBe(CrewPhaseStatus::Completed)
        ->and($phases[1]->status)->toBe(CrewPhaseStatus::Active)
        ->and($phases[1]->actual_start_at->toDateString())->toBe('2024-01-14')
        ->and($phases[1]->actual_end_at)->toBeNull();
});

test('phase inference: training end plus later on vessel closes P2A at on vessel and opens active P4', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Training To Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'training_started_at' => '2024-01-10',
            'training_ended_at' => '2024-01-14',
            'joined_vessel_at' => '2024-01-20',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($phases->pluck('phase_code')->all())->toBe([
            CrewPhaseCode::Training,
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::OnVessel,
        ])
        ->and($phases[1]->actual_end_at->toDateString())->toBe('2024-01-20')
        ->and($phases[2]->status)->toBe(CrewPhaseStatus::Active)
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);

    $sea = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();
    expect($sea->crew_assignment_phase_id)->toBe($phases[2]->id)
        ->and($sea->start_date?->toDateString())->toBe('2024-01-20')
        ->and($sea->end_date)->toBeNull()
        ->and($sea->total_months)->toBe(0)
        ->and($sea->total_days)->toBe(0);
});

test('training start only creates active P2B without inventing a training end', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Training Start Only Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'training_started_at' => '2024-06-01',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::Training)
        ->and($phases)->toHaveCount(1)
        ->and($phases[0]->phase_code)->toBe(CrewPhaseCode::Training)
        ->and($phases[0]->actual_start_at->toDateString())->toBe('2024-06-01')
        ->and($phases[0]->actual_end_at)->toBeNull()
        ->and($phases[0]->status)->toBe(CrewPhaseStatus::Active);
});

test('training start plus later on vessel without training end is blocked', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Missing Training End Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'training_started_at' => '2024-06-01',
            'joined_vessel_at' => '2024-06-15',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['training_ended_at']);

    expect($response->json('errors.training_ended_at.0'))
        ->toContain('Training End is required before On Vessel');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'training_started_at' => '2024-06-01',
            'joined_vessel_at' => '2024-06-15',
        ])
        ->assertSessionHasErrors(['training_ended_at']);

    expect(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('training start end and later on vessel reconstructs P2B then P2A then active P4', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Complete Training Then Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'training_started_at' => '2024-06-01',
            'training_ended_at' => '2024-06-05',
            'joined_vessel_at' => '2024-06-15',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->orderBy('sequence')->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($phases->pluck('phase_code')->all())->toBe([
            CrewPhaseCode::Training,
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::OnVessel,
        ])
        ->and($phases[0]->actual_start_at->toDateString())->toBe('2024-06-01')
        ->and($phases[0]->actual_end_at->toDateString())->toBe('2024-06-05')
        ->and($phases[1]->actual_start_at->toDateString())->toBe('2024-06-05')
        ->and($phases[1]->actual_end_at->toDateString())->toBe('2024-06-15')
        ->and($phases[2]->actual_start_at->toDateString())->toBe('2024-06-15')
        ->and($phases[2]->actual_end_at)->toBeNull()
        ->and($phases[2]->status)->toBe(CrewPhaseStatus::Active);
});

test('on vessel only creates active P4 without sea service', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('On Vessel Only', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    $phases = CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->get();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($phases)->toHaveCount(1)
        ->and($phases->first()->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);

    $sea = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();
    expect($sea->crew_assignment_phase_id)->toBe($phases->first()->id)
        ->and($sea->start_date?->toDateString())->toBe('2024-01-15')
        ->and($sea->end_date)->toBeNull()
        ->and($sea->total_months)->toBe(0)
        ->and($sea->total_days)->toBe(0);
});

test('existing active oms assignment blocks another active historical bootstrap', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Active Conflict Vessel', $company);
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'join_standby_at' => '2024-01-05',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignment']);

    expect($response->json('errors.assignment.0'))
        ->toContain('already has an active Crew Assignment');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
            'disembarked_at' => '2024-07-20',
        ])
        ->assertSessionHasErrors(['assignment']);

    expect(CrewAssignment::query()->where('employee_id', $employee->id)->where('source', 'historical_manual')->count())->toBe(0);
});

test('imported active P2A can continue with send to training', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Live P2A Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'join_standby_at' => '2024-01-05',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);

    $updated = app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::SendToTraining,
        ['occurred_at' => '2024-01-10 08:00:00'],
        $user->id,
    );

    expect($updated->currentPhase?->phase_code)->toBe(CrewPhaseCode::Training)
        ->and($updated->status)->toBe(CrewAssignmentStatus::Active);
});

test('imported active P2B can continue with complete training', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Live P2B Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'training_started_at' => '2024-01-10',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::Training);

    $updated = app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::CompleteTraining,
        [
            'occurred_at' => '2024-01-14 16:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
        ],
        $user->id,
    );

    expect($updated->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($updated->status)->toBe(CrewAssignmentStatus::Active);
});

test('imported active P4 can continue with confirm disembarkation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Live P4 Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-15',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);

    $updated = app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::ConfirmDisembarkation,
        [
            'occurred_at' => '2024-07-20 09:00:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
        ],
        $user->id,
    );

    expect($updated->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($updated->status)->toBe(CrewAssignmentStatus::Active);
});

test('imported active P5 can continue with travel home', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Live P5 Vessel', $company);

    grantCompanyPermissions($user, $company, [
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
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby);

    $updated = app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::TravelHome,
        [
            'occurred_at' => '2024-07-25 18:00:00',
            'completion_intent' => 'close',
        ],
        $user->id,
    );

    expect($updated->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($updated->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy);
});

test('at least one movement date is required', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('No Dates Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.preview'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['dates']);
});
