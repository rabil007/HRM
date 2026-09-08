<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetPreparationSkip;
use App\Models\CrewTimesheetSegment;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Spatie\Activitylog\Models\Activity;

function grantTimelineSkipPermissions(User $user, Company $company, array $extra = []): void
{
    grantCompanyPermissions($user, $company, array_values(array_unique(array_merge([
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.submit',
        'payroll.crew_timesheets.approve',
        'payroll.crew_timesheets.return',
        'payroll.crew_timesheets.apply_approved',
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.skip_timeline',
    ], $extra))));
}

function createSecondValidEmployee(array $fixtures): array
{
    $employee = Employee::factory()->create(['company_id' => $fixtures['company']->id]);
    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $fixtures['company']->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'basic_salary' => 120,
    ]);
    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    $assignment = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-TL-'.fake()->unique()->numerify('######'),
        'employee_id' => $employee->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $fixtures['vessel']->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
    ]);

    addTimelinePhase($assignment, CrewPhaseCode::OnVessel, 1, '2026-07-05 08:00:00', '2026-07-15 18:00:00');

    return compact('employee', 'contract', 'assignment');
}

// -------------------------------------------------------------------------
// Skip Tests (1 - 15)
// -------------------------------------------------------------------------

test('1. authorized user can skip an employee with a blocking warning', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    // Missing actual end date creates blocking missing_actual_end
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Movement correction pending next month',
        ])
        ->assertRedirect();

    $skip = CrewTimesheetPreparationSkip::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->first();

    expect($skip)->not->toBeNull()
        ->and($skip->reason)->toBe('Movement correction pending next month')
        ->and($skip->skipped_by)->toBe($fixtures['user']->id)
        ->and($skip->isActive())->toBeTrue();
});

test('2. reason is required', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => '',
        ])
        ->assertSessionHasErrors(['reason']);
});

test('3. reason length validation works (min 5, max 1000)', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Min 5
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'four',
        ])
        ->assertSessionHasErrors(['reason']);

    // Max 1000
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => str_repeat('a', 1001),
        ])
        ->assertSessionHasErrors(['reason']);
});

test('4. unauthorized user is forbidden', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    // Grant standard view/prepare but NOT skip_timeline
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Unauthorized skip attempt',
        ])
        ->assertForbidden();
});

test('5. cross-company employee cannot be skipped', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $other = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $other['employee']]), [
            'reason' => 'Cross company employee skip',
        ])
        ->assertNotFound();
});

test('6. cross-company preparation cannot be skipped', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $other = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($other['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $otherPreparation = app(PrepareCrewTimesheetTimeline::class)->handle($other['period'], $other['company']->id, $other['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $otherPreparation, $fixtures['employee']]), [
            'reason' => 'Cross company preparation skip',
        ])
        ->assertNotFound();
});

test('7. employee not present in preparation cannot be skipped', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Another employee in same company but not in preparation
    $unrelatedEmployee = Employee::factory()->create(['company_id' => $fixtures['company']->id]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $unrelatedEmployee]), [
            'reason' => 'Not in preparation employee',
        ])
        ->assertSessionHasErrors(['employee']);
});

test('8. employee with no warning cannot be skipped', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    // Valid phases - no warnings
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-20 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Trying to skip clean employee',
        ])
        ->assertSessionHasErrors(['employee']);
});

test('9. cross_company_reference cannot be skipped', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Inject cross_company_reference warning line
    CrewTimesheetPreparationLine::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_preparation_id' => $preparation->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'employee_id' => $fixtures['employee']->id,
        'phase_code' => CrewPhaseCode::JoinStandby->value,
        'pay_category' => CrewTimesheetPayCategory::Onsite->value,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-01',
        'warning_code' => CrewTimelineWarningCode::CrossCompanyReference->value,
        'days' => 0,
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Trying to skip cross company reference violation',
        ])
        ->assertSessionHasErrors(['employee']);
});

test('10. stale preparation cannot be skipped', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Add another phase to assignment to change phase hash and make preparation stale
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-10 08:00:00', '2026-07-20 18:00:00');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skip on stale preparation',
        ])
        ->assertSessionHasErrors(['preparation']);
});

test('11. non-latest preparation cannot be skipped', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparationV1 = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Prepare v2
    app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparationV1, $fixtures['employee']]), [
            'reason' => 'Skip on superseded v1',
        ])
        ->assertSessionHasErrors(['preparation']);
});

test('12. submitted preparation cannot be changed', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $preparation->update(['status' => CrewTimesheetPreparationStatus::Submitted]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skip on submitted preparation',
        ])
        ->assertSessionHasErrors(['preparation']);
});

test('13. approved preparation cannot be changed', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $preparation->update(['status' => CrewTimesheetPreparationStatus::Approved]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skip on approved preparation',
        ])
        ->assertSessionHasErrors(['preparation']);
});

test('14. applied preparation cannot be changed', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $preparation->update(['status' => CrewTimesheetPreparationStatus::Applied]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skip on applied preparation',
        ])
        ->assertSessionHasErrors(['preparation']);
});

test('15. returned preparation cannot be changed', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $preparation->update(['status' => CrewTimesheetPreparationStatus::Returned]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skip on returned preparation',
        ])
        ->assertSessionHasErrors(['preparation']);
});

// -------------------------------------------------------------------------
// Workflow Tests (16 - 20)
// -------------------------------------------------------------------------

test('16. a skippable blocking warning normally prevents Submit', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    // Overlapping phases = blocking warning
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-08 08:00:00', '2026-07-20 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors(['preparation']);
});

test('17. after employee skip, that blocker no longer prevents Submit', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    // Overlapping phases for employee 1
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-08 08:00:00', '2026-07-20 18:00:00');

    // Add employee 2 who is completely valid
    createSecondValidEmployee($fixtures);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip employee 1
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Overlapping phases will be reconciled next month',
        ])
        ->assertRedirect();

    // Now submit should succeed!
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Submitted);
});

test('18. approval succeeds when all remaining blockers are resolved/skipped', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);
    grantTimelineSkipPermissions($approver, $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-08 08:00:00', '2026-07-20 18:00:00');
    createSecondValidEmployee($fixtures);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip employee 1
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Overlapping phases will be reconciled next month',
        ]);

    // Submit
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));

    // Approve
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Approved);
});

test('19. apply succeeds when all remaining blockers are resolved/skipped', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);
    grantTimelineSkipPermissions($approver, $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-08 08:00:00', '2026-07-20 18:00:00');
    createSecondValidEmployee($fixtures);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip employee 1
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Overlapping phases will be reconciled next month',
        ]);

    // Submit & Approve
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));

    // Apply
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Applied);
});

test('20. a non-skippable cross-company blocker still prevents workflow', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Manually inject cross_company_reference line
    CrewTimesheetPreparationLine::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_preparation_id' => $preparation->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'employee_id' => $fixtures['employee']->id,
        'phase_code' => CrewPhaseCode::JoinStandby->value,
        'pay_category' => CrewTimesheetPayCategory::Onsite->value,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-01',
        'warning_code' => CrewTimelineWarningCode::CrossCompanyReference->value,
        'days' => 0,
    ]);

    // Attempting to submit must fail because cross_company_reference can never be resolved
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors(['preparation']);
});

// -------------------------------------------------------------------------
// Apply Tests (21 - 26)
// -------------------------------------------------------------------------

test('21. skipped employee receives no Crew Operations timesheet data', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);
    grantTimelineSkipPermissions($approver, $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-08 08:00:00', '2026-07-20 18:00:00');
    createSecondValidEmployee($fixtures);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Overlapping phases will be reconciled next month',
        ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]));

    // Skipped employee has NO timesheet created
    $timesheet = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->first();

    expect($timesheet)->toBeNull();
});

test('22. non-skipped employee is applied normally', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);
    grantTimelineSkipPermissions($approver, $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-08 08:00:00', '2026-07-20 18:00:00');
    $emp2 = createSecondValidEmployee($fixtures);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Overlapping phases will be reconciled next month',
        ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]));

    // Non-skipped employee 2 has timesheet created
    $timesheet = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $emp2['employee']->id)
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and((float) $timesheet->onsite_days)->toBe(11.0)
        ->and($timesheet->segments)->toHaveCount(1)
        ->and($timesheet->segments[0]->source)->toBe(CrewTimesheetSource::CrewOperations);
});

test('23. existing Manual data for skipped employee remains untouched', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);
    grantTimelineSkipPermissions($approver, $fixtures['company']);

    // Create existing manual timesheet for employee 1
    $manualTimesheet = CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'period_id' => $fixtures['period']->id,
        'employee_id' => $fixtures['employee']->id,
        'onsite_days' => 5,
        'remarks' => 'Manual entry by HR',
    ]);
    CrewTimesheetSegment::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_id' => $manualTimesheet->id,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-05',
        'days' => 5,
        'sequence' => 1,
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-08 08:00:00', '2026-07-20 18:00:00');
    createSecondValidEmployee($fixtures);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skipping employee 1, using manual data instead',
        ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]));

    // Check manual timesheet and segments are unchanged
    $freshManual = $manualTimesheet->fresh();
    expect((float) $freshManual->onsite_days)->toBe(5.0)
        ->and($freshManual->remarks)->toBe('Manual entry by HR')
        ->and($freshManual->segments)->toHaveCount(1)
        ->and($freshManual->segments[0]->source)->toBe(CrewTimesheetSource::Manual);
});

test('24. existing Import data for skipped employee remains untouched', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);
    grantTimelineSkipPermissions($approver, $fixtures['company']);

    // Create existing excel-import timesheet for employee 1
    $importTimesheet = CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'period_id' => $fixtures['period']->id,
        'employee_id' => $fixtures['employee']->id,
        'onsite_days' => 8,
        'remarks' => 'Excel import',
    ]);
    CrewTimesheetSegment::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_id' => $importTimesheet->id,
        'source' => CrewTimesheetSource::Import,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-08',
        'days' => 8,
        'sequence' => 1,
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-08 08:00:00', '2026-07-20 18:00:00');
    createSecondValidEmployee($fixtures);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Using imported data instead',
        ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]));

    // Check import timesheet and segments are unchanged
    $freshImport = $importTimesheet->fresh();
    expect((float) $freshImport->onsite_days)->toBe(8.0)
        ->and($freshImport->remarks)->toBe('Excel import')
        ->and($freshImport->segments)->toHaveCount(1)
        ->and($freshImport->segments[0]->source)->toBe(CrewTimesheetSource::Import);
});

test('25. skipped employee lines remain in preparation history', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);
    grantTimelineSkipPermissions($approver, $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-08 08:00:00', '2026-07-20 18:00:00');
    createSecondValidEmployee($fixtures);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $originalLineCount = $preparation->lines()->where('employee_id', $fixtures['employee']->id)->count();
    expect($originalLineCount)->toBeGreaterThan(0);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skip employee lines test',
        ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]));

    // Lines must NOT be deleted
    expect($preparation->lines()->where('employee_id', $fixtures['employee']->id)->count())->toBe($originalLineCount);
});

test('26. all-employees-skipped preparation can apply without dummy timesheets', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);
    grantTimelineSkipPermissions($approver, $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Only 1 employee in preparation, and we skip them
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Sole employee skipped',
        ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertRedirect();
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]))
        ->assertRedirect();
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Applied);
    expect(CrewTimesheet::query()->where('period_id', $fixtures['period']->id)->count())->toBe(0);
});

// -------------------------------------------------------------------------
// Restore Tests (27 - 30)
// -------------------------------------------------------------------------

test('27. restore makes employee active again', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skipped for now',
        ])
        ->assertRedirect();

    $skip = CrewTimesheetPreparationSkip::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->first();
    expect($skip->isActive())->toBeTrue();

    // Restore
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->delete(route('payroll.crew-timeline.employee-skip.restore', [$fixtures['period'], $preparation, $fixtures['employee']]))
        ->assertRedirect();

    $skip->refresh();
    expect($skip->isActive())->toBeFalse()
        ->and($skip->restored_by)->toBe($fixtures['user']->id)
        ->and($skip->restored_at)->not->toBeNull();
});

test('28. restored blocking warning prevents Submit again', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skipped for now',
        ]);

    // Restore
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->delete(route('payroll.crew-timeline.employee-skip.restore', [$fixtures['period'], $preparation, $fixtures['employee']]));

    // Now submit should fail due to restored blocking warning!
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors(['preparation']);
});

test('29. restore records actor and timestamp', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $restorer = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);
    grantTimelineSkipPermissions($restorer, $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip by user 1
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skipped by user 1',
        ]);

    // Restore by restorer
    $this->actingAs($restorer)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->delete(route('payroll.crew-timeline.employee-skip.restore', [$fixtures['period'], $preparation, $fixtures['employee']]));

    $skip = CrewTimesheetPreparationSkip::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->first();

    expect($skip->skipped_by)->toBe($fixtures['user']->id)
        ->and($skip->restored_by)->toBe($restorer->id)
        ->and($skip->restored_at)->not->toBeNull();
});

test('30. double restore is handled safely and idempotently', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skipped for now',
        ]);

    // Restore 1
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->delete(route('payroll.crew-timeline.employee-skip.restore', [$fixtures['period'], $preparation, $fixtures['employee']]))
        ->assertRedirect();

    // Restore 2 (idempotent redirect without error)
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->delete(route('payroll.crew-timeline.employee-skip.restore', [$fixtures['period'], $preparation, $fixtures['employee']]))
        ->assertRedirect();
});

// -------------------------------------------------------------------------
// Version Isolation Tests (31 - 32)
// -------------------------------------------------------------------------

test('31. skip belongs only to that preparation version', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparationV1 = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparationV1, $fixtures['employee']]), [
            'reason' => 'Version 1 skip decision',
        ]);

    expect($preparationV1->activeSkips()->count())->toBe(1);
});

test('32. preparing a newer version does not automatically inherit the skip', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparationV1 = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip in v1
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparationV1, $fixtures['employee']]), [
            'reason' => 'Version 1 skip decision',
        ]);

    // Return v1
    $preparationV1->update(['status' => CrewTimesheetPreparationStatus::Returned]);

    // Prepare v2
    $preparationV2 = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    expect($preparationV2->version)->toBe(2)
        ->and($preparationV2->skips()->count())->toBe(0)
        ->and($preparationV2->activeSkips()->count())->toBe(0);
});

// -------------------------------------------------------------------------
// Audit Tests (33 - 34)
// -------------------------------------------------------------------------

test('33. skip creates expected activity context', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Audited skip reason',
        ]);

    $activity = Activity::query()
        ->where('event', 'crew_timeline_employee_skipped')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($fixtures['user']->id)
        ->and($activity->properties['payroll_period_id'])->toBe($fixtures['period']->id)
        ->and($activity->properties['preparation_id'])->toBe($preparation->id)
        ->and($activity->properties['preparation_version'])->toBe($preparation->version)
        ->and($activity->properties['employee_id'])->toBe($fixtures['employee']->id)
        ->and($activity->properties['reason'])->toBe('Audited skip reason')
        ->and($activity->properties['warning_codes'])->toContain(CrewTimelineWarningCode::MissingActualEnd->value);
});

test('34. restore creates expected activity context', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Audited skip reason',
        ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->delete(route('payroll.crew-timeline.employee-skip.restore', [$fixtures['period'], $preparation, $fixtures['employee']]));

    $activity = Activity::query()
        ->where('event', 'crew_timeline_employee_skip_restored')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($fixtures['user']->id)
        ->and($activity->properties['payroll_period_id'])->toBe($fixtures['period']->id)
        ->and($activity->properties['preparation_id'])->toBe($preparation->id)
        ->and($activity->properties['preparation_version'])->toBe($preparation->version)
        ->and($activity->properties['employee_id'])->toBe($fixtures['employee']->id);
});
