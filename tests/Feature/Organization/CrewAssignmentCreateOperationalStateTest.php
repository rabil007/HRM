<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CompanyVisaType;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionFieldCatalog;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionPresenter;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewOperations\CrewOperationsSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

// ============================================================
// 1. Access control
// ============================================================

test('1. crew assignment create page requires create permission', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->get(route('organization.crew-assignments.create'))
        ->assertRedirect(route('login'));

    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertForbidden();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk();
});

// ============================================================
// 2. Create + View → full metadata is present
// ============================================================

test('2. user with create and view gets full operational metadata', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Full Meta Vessel', $company);
    $assign = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->has("form_options.employee_status_by_employee.{$employee->id}", fn (Assert $item) => $item
                ->where('assignment_id', $assign->id)
                ->where('assignment_no', $assign->assignment_no)
                ->where('has_active_assignment', true)
                ->etc()
            )
        );
});

// ============================================================
// 3. Create only (no view) → sensitive metadata stripped; has_active_assignment still present
// ============================================================

test('3. user with create but without view does not receive sensitive assignment metadata', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        // Intentionally NO view permission.
    ]);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Restricted Vessel', $company);
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            // has_active_assignment MUST still be present (minimum intelligence for UI blocking)
            ->where("form_options.employee_status_by_employee.{$employee->id}.has_active_assignment", true)
            // Sensitive fields MUST be null
            ->where("form_options.employee_status_by_employee.{$employee->id}.assignment_id", null)
            ->where("form_options.employee_status_by_employee.{$employee->id}.assignment_no", null)
            ->where("form_options.employee_status_by_employee.{$employee->id}.vessel_name", null)
            ->where("form_options.employee_status_by_employee.{$employee->id}.current_vessel", null)
        );
});

// ============================================================
// 4. Cross-company isolation
// ============================================================

test('4. cross-company assignment information never appears in operational status or transfer map', function () {
    ['user' => $userA, 'company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB, 'employee' => $employeeB, 'rank' => $rankB] = makeCrewAssignmentFixtures();
    $vesselB = makeCrewMovementVessel('Company B Vessel', $companyB);
    makeActiveOnVesselAssignment($companyB, $employeeB, $rankB, $vesselB);

    grantCompanyPermissions($userA, $companyA, ['crew_operations.assignments.create']);
    $userA->update(['current_company_id' => $companyA->id]);

    $this->actingAs($userA)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing("form_options.employee_status_by_employee.{$employeeB->id}")
            ->missing("form_options.active_on_vessel_by_employee.{$employeeB->id}")
        );
});

// ============================================================
// 5. P2A (Join Standby) → has_active_assignment = true
// ============================================================

test('5. active P2A join standby employee is recognised as having an active assignment', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('P2A Vessel', $company);
    $emp = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    makeCurrentCrewPhaseAssignment($company, $emp, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("form_options.employee_status_by_employee.{$emp->id}.status", 'join_standby')
            ->where("form_options.employee_status_by_employee.{$emp->id}.has_active_assignment", true)
        );
});

// ============================================================
// 6. P5 (Demob Standby) → has_active_assignment = true
// ============================================================

test('6. active P5 demob standby employee is recognised as having an active assignment', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('P5 Vessel', $company);
    $emp = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    makeCurrentCrewPhaseAssignment($company, $emp, $rank, $vessel, CrewPhaseCode::DemobStandby);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("form_options.employee_status_by_employee.{$emp->id}.status", 'demob_standby')
            ->where("form_options.employee_status_by_employee.{$emp->id}.has_active_assignment", true)
        );
});

// ============================================================
// 7. P4 On Vessel → Transfer Vessel recommendation data preserved
// ============================================================

test('7. active P4 on vessel employee keeps transfer vessel recommendation data', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Transfer Rec Vessel', $company);
    $assign = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has("form_options.active_on_vessel_by_employee.{$employee->id}", fn (Assert $item) => $item
                ->where('assignment_id', $assign->id)
                ->where('assignment_no', $assign->assignment_no)
                ->where('vessel_id', $vessel->id)
                ->where('vessel_name', $vessel->name)
                ->where('can_transfer', true)
                ->etc()
            )
            ->where("form_options.employee_status_by_employee.{$employee->id}.has_active_assignment", true)
        );
});

// ============================================================
// 8. Completed assignment → employee is available (has_active_assignment = false)
// ============================================================

test('8. completed assignment employee is available for new cycle', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Completed Vessel', $company);
    $emp = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);

    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-COMP-'.uniqid(),
        'employee_id' => $emp->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::parse('2026-01-01'),
        'closed_at' => CarbonImmutable::parse('2026-06-01'),
        'source' => 'manual',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("form_options.employee_status_by_employee.{$emp->id}.status", 'in_home')
            ->where("form_options.employee_status_by_employee.{$emp->id}.has_active_assignment", false)
        );
});

// ============================================================
// 9. Generic error validation path (CrewMovementException → form.errors.error)
// ============================================================

test('9. generic error validation path is supported when backend rejects duplicate active assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Backend Reject Vessel', $company);

    // Create an active assignment so the backend will reject the second.
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    // Attempt to POST a new draft assignment for the same employee.
    $response = $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-09-01',
        ]);

    // The backend converts CrewMovementException to a ValidationException with the 'error' key.
    $response->assertSessionHasErrors(['error']);
});

// ============================================================
// 10. Top-level duplicate prop is removed
// ============================================================

test('10. top-level employee_status_by_employee prop is absent from create page', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->missing('employee_status_by_employee')
        );
});

// ============================================================
// 11. form_options.employee_status_by_employee remains present
// ============================================================

test('11. form_options.employee_status_by_employee remains present on create page', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->has('form_options.employee_status_by_employee')
            ->where("form_options.employee_status_by_employee.{$employee->id}.status", 'in_home')
        );
});

// ============================================================
// 12. Employee visa type functionality remains unaffected
// ============================================================

test('12. employee company_visa_type_id column and relation remain unaffected', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $visaType = CompanyVisaType::query()->create([
        'name' => 'Employee Visa '.uniqid(),
        'is_active' => true,
    ]);

    expect(Schema::hasTable('company_visa_types'))->toBeTrue()
        ->and(Schema::hasColumn('employees', 'company_visa_type_id'))->toBeTrue();

    $employee->update(['company_visa_type_id' => $visaType->id]);
    expect($employee->fresh()->company_visa_type_id)->toBe($visaType->id);
});

// ============================================================
// 13. Employee contract visa type functionality remains unaffected
// ============================================================

test('13. employee_contracts company_visa_type_id column and relation remain unaffected', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $visaType = CompanyVisaType::query()->create([
        'name' => 'Contract Visa '.uniqid(),
        'is_active' => true,
    ]);

    expect(Schema::hasColumn('employee_contracts', 'company_visa_type_id'))->toBeTrue();

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'company_visa_type_id' => $visaType->id,
    ]);

    expect($contract->fresh()->company_visa_type_id)->toBe($visaType->id)
        ->and($contract->fresh()->companyVisaType->name)->toBe($visaType->name);
});

// ============================================================
// 14. Legacy corrections containing company_visa_type_id do not crash the presenter
// ============================================================

test('14. historical correction with company_visa_type_id in JSON does not crash the correction presenter', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Legacy Corr Vessel', $company);
    $assign = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assign->currentPhase;

    // Simulate a historical correction that was saved with the now-removed company_visa_type_id field.
    $correction = CrewMovementCorrection::factory()
        ->forAssignment($assign, $phase)
        ->approved()
        ->create([
            'original_values' => [
                'company_visa_type_id' => ['value' => 999, 'display' => 'Legacy Visa'],
                'actual_start_at' => ['value' => now()->subDays(10)->toIso8601String(), 'display' => '2026-01-01 08:00'],
            ],
            'proposed_values' => [
                'company_visa_type_id' => ['value' => 888, 'display' => 'Another Legacy Visa'],
                'actual_start_at' => ['value' => now()->subDays(9)->toIso8601String(), 'display' => '2026-01-02 08:00'],
            ],
        ]);

    // Load the relationships the presenter expects
    $assign->load([
        'employee',
        'vessel',
        'company:id,timezone',
        'phases.pendingCorrections',
        'corrections.requester:id,name',
        'corrections.decisionMaker:id,name',
        'corrections.phase',
        'corrections.company:id,timezone',
    ]);

    // Should NOT throw; the presenter just returns raw JSON as-is for historical values.
    $presenter = app(CrewMovementCorrectionPresenter::class);
    $result = $presenter->assignmentSummary($assign);

    expect($result)->toBeArray()
        ->and($result['history'])->not->toBeEmpty();

    // The legacy field should still be in the returned data without crashing.
    $historyItem = collect($result['history'])->first(fn ($item) => $item['id'] === $correction->id);
    expect($historyItem)->not->toBeNull();
});

// ============================================================
// 15. New corrections cannot request company_visa_type_id
// ============================================================

test('15. CrewMovementCorrectionFieldCatalog does not include company_visa_type_id in allowed fields', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Catalog Test Vessel', $company);
    $assign = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assign->currentPhase;

    $catalog = new CrewMovementCorrectionFieldCatalog;
    $allowed = $catalog->allowedFields($phase);

    expect($allowed)->not->toContain('company_visa_type_id');

    // Also verify the catalog will not accept it as an assignment field.
    expect($catalog->isAssignmentField('company_visa_type_id'))->toBeFalse();
});

// ============================================================
// Regression: operational status resolves correctly for multiple phases
// ============================================================

test('employee operational status resolves on_vessel, join_standby, demob_standby, and available properly', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $vessel = makeCrewMovementVessel('Status Test Vessel', $company);

    // Employee 1: On Vessel (P4)
    $empOnVessel = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $assignOnVessel = makeActiveOnVesselAssignment($company, $empOnVessel, $rank, $vessel);

    // Employee 2: Join Standby (P2A)
    $empJoinStandby = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $assignJoinStandby = makeCurrentCrewPhaseAssignment($company, $empJoinStandby, $rank, $vessel, CrewPhaseCode::JoinStandby);

    // Employee 3: Demob Standby (P5)
    $empDemobStandby = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $assignDemobStandby = makeCurrentCrewPhaseAssignment($company, $empDemobStandby, $rank, $vessel, CrewPhaseCode::DemobStandby);

    // Employee 4: Available (no active assignment)
    $empAvailable = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($empOnVessel, $assignOnVessel, $vessel, $empJoinStandby, $assignJoinStandby, $empDemobStandby, $assignDemobStandby, $empAvailable) {
            $page->component('organization/crew/create')
                // Employee 1: On Vessel
                ->where("form_options.employee_status_by_employee.{$empOnVessel->id}.status", 'on_vessel')
                ->where("form_options.employee_status_by_employee.{$empOnVessel->id}.has_active_assignment", true)
                ->where("form_options.employee_status_by_employee.{$empOnVessel->id}.assignment_id", $assignOnVessel->id)
                ->where("form_options.employee_status_by_employee.{$empOnVessel->id}.assignment_no", $assignOnVessel->assignment_no)
                ->where("form_options.employee_status_by_employee.{$empOnVessel->id}.current_vessel", $vessel->name)
                // Employee 2: Join Standby
                ->where("form_options.employee_status_by_employee.{$empJoinStandby->id}.status", 'join_standby')
                ->where("form_options.employee_status_by_employee.{$empJoinStandby->id}.has_active_assignment", true)
                ->where("form_options.employee_status_by_employee.{$empJoinStandby->id}.assignment_id", $assignJoinStandby->id)
                ->where("form_options.employee_status_by_employee.{$empJoinStandby->id}.assignment_no", $assignJoinStandby->assignment_no)
                // Employee 3: Demob Standby
                ->where("form_options.employee_status_by_employee.{$empDemobStandby->id}.status", 'demob_standby')
                ->where("form_options.employee_status_by_employee.{$empDemobStandby->id}.has_active_assignment", true)
                ->where("form_options.employee_status_by_employee.{$empDemobStandby->id}.assignment_id", $assignDemobStandby->id)
                ->where("form_options.employee_status_by_employee.{$empDemobStandby->id}.assignment_no", $assignDemobStandby->assignment_no)
                // Employee 4: Available
                ->where("form_options.employee_status_by_employee.{$empAvailable->id}.status", 'in_home')
                ->where("form_options.employee_status_by_employee.{$empAvailable->id}.has_active_assignment", false);
        });
});

// ============================================================
// Regression: crew create/store no longer accepts or persists company_visa_type_id
// ============================================================

test('crew create/store no longer accepts or persists company_visa_type_id', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Store Visa Test Vessel', $company);

    $visa = CompanyVisaType::query()->create(['name' => 'Legacy Visa '.uniqid(), 'is_active' => true]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'company_visa_type_id' => $visa->id,
            'planned_join_at' => '2026-09-01',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();

    expect(Schema::hasColumn('crew_assignments', 'company_visa_type_id'))->toBeFalse()
        ->and((new CrewAssignment)->getFillable())->not->toContain('company_visa_type_id')
        ->and(method_exists($assignment, 'companyVisaType'))->toBeFalse();
});

// ============================================================
// Regression: vessel transfer and redeployment movements work cleanly
// ============================================================

test('vessel transfer and redeployment movements work cleanly without visa type', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $sourceVessel = makeCrewMovementVessel('Transfer Src Vessel', $company);
    $destVessel = makeCrewMovementVessel('Transfer Dst Vessel', $company);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $sourceVessel);

    $service = app(CrewMovementService::class);

    // Transfer vessel
    $destAssignment = $service->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-08-01 10:00:00',
            'vessel_id' => $destVessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    );

    expect($destAssignment->vessel_id)->toBe($destVessel->id)
        ->and($destAssignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Completed);

    // Move to P5 Demob Standby on dest assignment
    $p4 = $destAssignment->currentPhase;
    $p4->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => '2026-08-10 10:00:00',
    ]);

    $p5 = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $destAssignment->id,
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-08-10 10:00:00',
    ]);
    $destAssignment->update(['current_phase_id' => $p5->id]);

    // Redeploy to another assignment
    $redeployVessel = makeCrewMovementVessel('Redeploy Target Vessel', $company);
    $redeployAssignment = $service->perform(
        $company->id,
        $destAssignment->id,
        CrewMovementAction::Redeploy,
        [
            'occurred_at' => '2026-08-11 09:00:00',
            'starting_phase' => 'p0',
            'vessel_id' => $redeployVessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    );

    expect($redeployAssignment->vessel_id)->toBe($redeployVessel->id)
        ->and($redeployAssignment->status)->toBe(CrewAssignmentStatus::Draft);
});

// ============================================================
// Regression: company_timezone is exposed in form_options
// ============================================================

test('company_timezone is included in form_options on create page', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->where('form_options.company_timezone', 'Asia/Dubai')
        );
});

// ============================================================
// Regression: P0 draft does NOT block create (has_active_assignment = false for draft)
// ============================================================

test('create page exposes max_home_days and home availability readiness for in-home employees', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    CrewOperationsSettings::saveSettings($company->id, [], 30);

    $vessel = makeCrewMovementVessel('Home Readiness Vessel', $company);
    $employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-HOME-'.uniqid(),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::today('Asia/Dubai')->subDays(50),
        'closed_at' => CarbonImmutable::today('Asia/Dubai')->subDays(36),
        'source' => 'manual',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('form_options.max_home_days', 30)
            ->where("form_options.employee_status_by_employee.{$employee->id}.status", 'in_home')
            ->where("form_options.employee_status_by_employee.{$employee->id}.days_at_home", 36)
            ->where("form_options.employee_status_by_employee.{$employee->id}.availability_status", 'over_limit')
            ->where("form_options.employee_status_by_employee.{$employee->id}.availability_detail", '6 days over availability limit')
        );
});

test('create page employee options include profile fields for assignment readiness', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
    $user->update(['current_company_id' => $company->id]);

    $employee->update([
        'image' => 'employees/test-avatar.jpg',
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
        );

    $matched = collect($response->inertiaProps('form_options.employees'))
        ->firstWhere('id', $employee->id);

    expect($matched)->not->toBeNull()
        ->and($matched['image'])->toBe('employees/test-avatar.jpg');
});

test('home availability readiness fields are stripped without assignments view permission', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-HOME-RESTRICTED-'.uniqid(),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::parse('2026-06-01'),
        'closed_at' => CarbonImmutable::parse('2026-08-01'),
        'source' => 'manual',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("form_options.employee_status_by_employee.{$employee->id}.days_at_home", null)
            ->where("form_options.employee_status_by_employee.{$employee->id}.availability_status", null)
            ->where("form_options.employee_status_by_employee.{$employee->id}.availability_detail", null)
        );
});

test('P0 draft assignment does not set has_active_assignment to true', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Create a Draft (P0) assignment directly
    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-DRAFT-'.uniqid(),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'status' => CrewAssignmentStatus::Draft,
        'source' => 'manual',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("form_options.employee_status_by_employee.{$employee->id}.status", 'pre_mobilisation')
            ->where("form_options.employee_status_by_employee.{$employee->id}.has_active_assignment", false)
        );
});

test('edit assignment page includes operational status context for locked employee', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.update',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Edit Context Vessel', $company);

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-EDIT-'.uniqid(),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
    ]);

    CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => CarbonImmutable::parse('2026-09-03 08:00:00', 'Asia/Dubai'),
    ]);

    $assignment->update(['current_phase_id' => $assignment->phases()->first()->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("form_options.employee_status_by_employee.{$employee->id}.status", 'join_standby')
            ->where('form_options.max_home_days', CrewOperationsSettings::maxHomeDays($company->id))
            ->has('form_options.company_timezone')
        );
});
