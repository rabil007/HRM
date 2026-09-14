<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CompanyVisaType;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\CrewMovements\CrewMovementService;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

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

test('2. create props contain selectable employees and no visa types', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->has('form_options.employees', 1)
            ->where('form_options.employees.0.id', $employee->id)
            ->where('form_options.employees.0.name', $employee->name)
            ->where('form_options.employees.0.employee_no', $employee->employee_no)
            ->where('form_options.employees.0.rank_id', $rank->id)
            ->missing('form_options.visa_types')
            ->has('employee_status_by_employee')
            ->has('form_options.employee_status_by_employee'));
});

test('3. employee operational status resolves on_vessel, join_standby, demob_standby, and available properly', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
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

    $response = $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk();

    $response->assertInertia(function (Assert $page) use ($empOnVessel, $assignOnVessel, $vessel, $empJoinStandby, $assignJoinStandby, $empDemobStandby, $assignDemobStandby, $empAvailable) {
        $page->component('organization/crew/create')
            // Employee 1: On Vessel
            ->where("employee_status_by_employee.{$empOnVessel->id}.status", 'on_vessel')
            ->where("employee_status_by_employee.{$empOnVessel->id}.assignment_id", $assignOnVessel->id)
            ->where("employee_status_by_employee.{$empOnVessel->id}.assignment_no", $assignOnVessel->assignment_no)
            ->where("employee_status_by_employee.{$empOnVessel->id}.current_vessel", $vessel->name)
            // Employee 2: Join Standby
            ->where("employee_status_by_employee.{$empJoinStandby->id}.status", 'join_standby')
            ->where("employee_status_by_employee.{$empJoinStandby->id}.assignment_id", $assignJoinStandby->id)
            ->where("employee_status_by_employee.{$empJoinStandby->id}.assignment_no", $assignJoinStandby->assignment_no)
            // Employee 3: Demob Standby
            ->where("employee_status_by_employee.{$empDemobStandby->id}.status", 'demob_standby')
            ->where("employee_status_by_employee.{$empDemobStandby->id}.assignment_id", $assignDemobStandby->id)
            ->where("employee_status_by_employee.{$empDemobStandby->id}.assignment_no", $assignDemobStandby->assignment_no)
            // Employee 4: Available / In Home
            ->where("employee_status_by_employee.{$empAvailable->id}.status", 'in_home');
    });
});

test('4. cross-company assignment information never appears in operational status or transfer map', function () {
    ['user' => $userA, 'company' => $companyA, 'rank' => $rankA] = makeCrewAssignmentFixtures();
    ['company' => $companyB, 'employee' => $employeeB, 'rank' => $rankB] = makeCrewAssignmentFixtures();
    $vesselB = makeCrewMovementVessel('Company B Vessel', $companyB);
    $assignB = makeActiveOnVesselAssignment($companyB, $employeeB, $rankB, $vesselB);

    grantCompanyPermissions($userA, $companyA, ['crew_operations.assignments.create']);
    $userA->update(['current_company_id' => $companyA->id]);

    $this->actingAs($userA)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing("employee_status_by_employee.{$employeeB->id}")
            ->missing("form_options.active_on_vessel_by_employee.{$employeeB->id}"));
});

test('5. crew create/store no longer accepts or persists company_visa_type_id', function () {
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

test('6. existing active-on-vessel to transfer vessel recommendation data is preserved', function () {
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
            ));
});

test('7. vessel transfer and redeployment movements work cleanly without visa type', function () {
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

test('8. employee and contract company visa type functionality remains unaffected', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $visaType = CompanyVisaType::query()->create([
        'name' => 'Freezone Visa '.uniqid(),
        'is_active' => true,
    ]);

    expect(Schema::hasTable('company_visa_types'))->toBeTrue()
        ->and(Schema::hasColumn('employees', 'company_visa_type_id'))->toBeTrue()
        ->and(Schema::hasColumn('employee_contracts', 'company_visa_type_id'))->toBeTrue();

    $employee->update(['company_visa_type_id' => $visaType->id]);
    expect($employee->fresh()->company_visa_type_id)->toBe($visaType->id);

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'company_visa_type_id' => $visaType->id,
    ]);

    expect($contract->fresh()->company_visa_type_id)->toBe($visaType->id)
        ->and($contract->fresh()->companyVisaType->name)->toBe($visaType->name);
});
