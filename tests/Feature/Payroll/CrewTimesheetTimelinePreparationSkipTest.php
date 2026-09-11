<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetMode;
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
use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use App\Support\Payroll\CrewOperationsPayrollGenerationGuard;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewQuery;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewResource;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationSkipResolver;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Illuminate\Validation\ValidationException;
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
        'end_date' => null,
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
        ->and($activity->properties['company_id'])->toBe($fixtures['company']->id)
        ->and($activity->properties['payroll_period_id'])->toBe($fixtures['period']->id)
        ->and($activity->properties['preparation_id'])->toBe($preparation->id)
        ->and($activity->properties['preparation_version'])->toBe($preparation->version)
        ->and($activity->properties['employee_id'])->toBe($fixtures['employee']->id)
        ->and($activity->properties['original_skip_reason'])->toBe('Audited skip reason')
        ->and($activity->properties['warning_codes'])->toContain(CrewTimelineWarningCode::MissingActualEnd->value);
});

// -------------------------------------------------------------------------
// Tenant Isolation Tests (35)
// -------------------------------------------------------------------------

test('35. skip record from another company cannot affect unresolved warning state even under malformed fixture data', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Other company fixtures
    ['company' => $otherCompany, 'user' => $otherUser] = makePayrollFixtures();

    // Create a malformed skip record belonging to $otherCompany pointing to $preparation->id
    CrewTimesheetPreparationSkip::query()->create([
        'company_id' => $otherCompany->id,
        'crew_timesheet_preparation_id' => $preparation->id,
        'employee_id' => $fixtures['employee']->id,
        'skipped_by' => $otherUser->id,
        'skipped_at' => now(),
        'reason' => 'Malformed cross-tenant skip injection',
        'created_at' => now(),
    ]);

    $resolver = app(CrewTimesheetPreparationSkipResolver::class);

    // Query path: Active skipped IDs for this preparation must NOT resolve the malformed foreign company skip
    $skippedIds = $resolver->activeSkippedEmployeeIds($preparation);
    expect($skippedIds)->toBe([]);

    // Unresolved warning count must remain 1
    $unresolvedCount = $resolver->unresolvedBlockingWarningCount($preparation);
    expect($unresolvedCount)->toBe(1);

    // Eager-loaded path: even if foreign skip is somehow in memory, defensive filtering ignores it
    $preparation->load('skips');
    $eagerSkippedIds = $resolver->activeSkippedEmployeeIds($preparation);
    expect($eagerSkippedIds)->toBe([]);
    expect($resolver->unresolvedBlockingWarningCount($preparation))->toBe(1);
});

test('review resource ignores foreign-company lines and skips when relations are not preloaded', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $baselinePreparation = $preparation->fresh();
    expect($baselinePreparation->relationLoaded('lines'))->toBeFalse()
        ->and($baselinePreparation->relationLoaded('skips'))->toBeFalse();

    $baseline = app(CrewTimesheetPreparationReviewResource::class)->toArray($fixtures['period'], $baselinePreparation);

    ['company' => $otherCompany, 'user' => $otherUser, 'employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();
    $otherVessel = makeCrewMovementVessel('Foreign Timeline Vessel', $otherCompany);
    $otherAssignment = CrewAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'assignment_no' => 'CA-TL-'.fake()->unique()->numerify('######'),
        'employee_id' => $otherEmployee->id,
        'rank_id' => $otherRank->id,
        'vessel_id' => $otherVessel->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
    ]);

    $foreignLine = CrewTimesheetPreparationLine::query()->create([
        'company_id' => $otherCompany->id,
        'crew_timesheet_preparation_id' => $preparation->id,
        'employee_id' => $otherEmployee->id,
        'crew_assignment_id' => $otherAssignment->id,
        'phase_code' => CrewPhaseCode::OnVessel->value,
        'pay_category' => CrewTimesheetPayCategory::Onsite->value,
        'from_date' => '2026-07-05',
        'to_date' => '2026-07-10',
        'days' => 6,
        'warning_code' => CrewTimelineWarningCode::MissingActualEnd->value,
        'remarks' => 'Malformed cross-tenant line injection',
    ]);

    CrewTimesheetPreparationSkip::query()->create([
        'company_id' => $otherCompany->id,
        'crew_timesheet_preparation_id' => $preparation->id,
        'employee_id' => $fixtures['employee']->id,
        'skipped_by' => $otherUser->id,
        'skipped_at' => now(),
        'reason' => 'Malformed cross-tenant skip injection',
    ]);

    $unloadedPreparation = $preparation->fresh();
    expect($unloadedPreparation->relationLoaded('lines'))->toBeFalse()
        ->and($unloadedPreparation->relationLoaded('skips'))->toBeFalse();

    $payload = app(CrewTimesheetPreparationReviewResource::class)
        ->toArray($fixtures['period'], $unloadedPreparation);

    $employeeIds = collect($payload['employees'])->pluck('employee_id');
    $lineIds = collect($payload['employees'])->flatMap(
        fn (array $employee): array => collect($employee['lines'] ?? [])->pluck('id')->all(),
    );
    $companyAEmployee = collect($payload['employees'])->firstWhere('employee_id', $fixtures['employee']->id);

    expect($employeeIds)->not->toContain((int) $otherEmployee->id)
        ->and($lineIds->all())->not->toContain($foreignLine->id)
        ->and($companyAEmployee)->not->toBeNull()
        ->and($companyAEmployee['is_skipped'])->toBeFalse()
        ->and($companyAEmployee['skip_reason'])->toBeNull()
        ->and($payload['summary'])->toBe($baseline['summary'])
        ->and($payload['warning_breakdown'])->toBe($baseline['warning_breakdown'])
        ->and($payload['preparation']['has_non_skippable_integrity_error'])
        ->toBe($baseline['preparation']['has_non_skippable_integrity_error']);
});

// -------------------------------------------------------------------------
// Cross-Company Integrity Error Alignment Tests (36)
// -------------------------------------------------------------------------

test('36. preparation-level cross-company reference disables can_skip for all employees in review payload and rejects backend skip', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    // Employee 1 has skippable warning (MissingActualEnd)
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);

    // Employee 2
    ['employee' => $secondEmployee] = createSecondValidEmployee($fixtures);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Inject cross_company_reference warning on Employee 2's line
    CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('employee_id', $secondEmployee->id)
        ->firstOrFail()
        ->update([
            'warning_code' => CrewTimelineWarningCode::CrossCompanyReference->value,
            'remarks' => 'Cross-company data isolation violation',
        ]);

    $loadedPrep = app(CrewTimesheetPreparationReviewQuery::class)->findForReview($fixtures['period'], (int) $preparation->id, (int) $fixtures['company']->id);
    $payload = app(CrewTimesheetPreparationReviewResource::class)->toArray($fixtures['period'], $loadedPrep);

    expect($payload['preparation']['has_non_skippable_integrity_error'])->toBeTrue()
        ->and($payload['summary']['has_non_skippable_integrity_error'])->toBeTrue();

    $emp1Payload = collect($payload['employees'])->firstWhere('employee_id', $fixtures['employee']->id);
    $emp2Payload = collect($payload['employees'])->firstWhere('employee_id', $secondEmployee->id);

    expect($emp1Payload['has_cross_company_warning'])->toBeFalse()
        ->and($emp1Payload['has_non_skippable_integrity_error'])->toBeTrue()
        ->and($emp1Payload['can_skip'])->toBeFalse()
        ->and($emp2Payload['has_cross_company_warning'])->toBeTrue()
        ->and($emp2Payload['has_non_skippable_integrity_error'])->toBeTrue()
        ->and($emp2Payload['can_skip'])->toBeFalse();

    // Attempting backend skip for Employee 1 (who only had MissingActualEnd) is rejected
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Attempting skip despite cross-company integrity error',
        ])
        ->assertSessionHasErrors(['employee']);
});

// -------------------------------------------------------------------------
// Warning Breakdown Consistency Tests (37)
// -------------------------------------------------------------------------

test('37. warning breakdown exposes total_count, unresolved_count, and skipped_count per warning code', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company']);

    // Employee 1 has 2 blocking warnings
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 2, '2026-07-15 08:00:00', null, CrewPhaseStatus::Completed);

    // Employee 2 has 1 blocking warning
    ['employee' => $secondEmployee, 'assignment' => $secondAssignment] = createSecondValidEmployee($fixtures);
    addTimelinePhase($secondAssignment, CrewPhaseCode::JoinStandby, 2, '2026-07-20 08:00:00', null, CrewPhaseStatus::Completed);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip Employee 1
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skipping Employee 1',
        ])
        ->assertRedirect();

    $loadedPrep = app(CrewTimesheetPreparationReviewQuery::class)->findForReview($fixtures['period'], (int) $preparation->id, (int) $fixtures['company']->id);
    $payload = app(CrewTimesheetPreparationReviewResource::class)->toArray($fixtures['period'], $loadedPrep);

    $missingActualEndBreakdown = collect($payload['warning_breakdown'])
        ->firstWhere('code', CrewTimelineWarningCode::MissingActualEnd->value);

    expect($missingActualEndBreakdown)->not->toBeNull()
        ->and($missingActualEndBreakdown['total_count'])->toBe(3)
        ->and($missingActualEndBreakdown['skipped_count'])->toBe(2)
        ->and($missingActualEndBreakdown['unresolved_count'])->toBe(1)
        ->and($missingActualEndBreakdown['count'])->toBe(3)
        ->and($payload['summary']['unresolved_blocking_warning_count'])->toBe(1)
        ->and($payload['summary']['blocking_warning_count'])->toBe(3);
});

// -------------------------------------------------------------------------
// Final Hybrid Generation Regression Tests (38 - 41)
// -------------------------------------------------------------------------

test('38. Test A — hybrid mode: skipped timeline + manual replacement is ready and generates payroll using manual data', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);

    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company'], ['payroll.periods.update', 'payroll.periods.view']);
    grantTimelineSkipPermissions($approver, $fixtures['company'], ['payroll.periods.update', 'payroll.periods.view']);

    // Employee has blocking warning
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip Employee
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Using manual replacement data',
        ])
        ->assertRedirect();

    // Submit -> Approve -> Apply
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]));

    // Apply created no timesheet for skipped employee
    expect(CrewTimesheet::query()->where('period_id', $fixtures['period']->id)->where('employee_id', $fixtures['employee']->id)->exists())->toBeFalse();

    // Enter approved manual replacement timesheet
    $timesheet = app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period']->fresh(),
        $fixtures['employee'],
        [
            'sign_on_standby_from' => '2026-07-01',
            'sign_on_standby_to' => '2026-07-03',
            'sign_on_standby_days' => 3,
            'onsite_from' => '2026-07-04',
            'onsite_to' => '2026-07-13',
            'onsite_days' => 10,
            'source' => CrewTimesheetSource::Manual,
        ],
        $fixtures['user']->id,
    );
    expect($timesheet->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved);

    // Preview / readiness check
    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($fixtures['period']->fresh(), (int) $fixtures['company']->id);
    expect($preview->ready)->toBeTrue()
        ->and($preview->canGenerate)->toBeTrue()
        ->and($preview->readyEmployeeIds)->toContain((int) $fixtures['employee']->id)
        ->and($preview->missingTimesheetCount)->toBe(0);

    // Generate payroll
    $result = app(GenerateCrewPayroll::class)->handle($fixtures['period']->fresh());
    expect($result->errors)->toBe([]);

    $record = PayrollRecord::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->basic_salary)->toBeGreaterThan(0);
});

test('39. Test B — hybrid mode: skipped timeline + import replacement is ready and generates payroll using import data', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);

    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company'], ['payroll.periods.update', 'payroll.periods.view']);
    grantTimelineSkipPermissions($approver, $fixtures['company'], ['payroll.periods.update', 'payroll.periods.view']);

    // Employee has blocking warning
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip Employee
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Using imported replacement data',
        ])
        ->assertRedirect();

    // Submit -> Approve -> Apply
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]));

    // Enter approved import replacement timesheet
    $timesheet = app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period']->fresh(),
        $fixtures['employee'],
        [
            'sign_on_standby_from' => '2026-07-01',
            'sign_on_standby_to' => '2026-07-02',
            'sign_on_standby_days' => 2,
            'onsite_from' => '2026-07-03',
            'onsite_to' => '2026-07-10',
            'onsite_days' => 8,
            'source' => CrewTimesheetSource::Import,
        ],
        $fixtures['user']->id,
    );
    expect($timesheet->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved);

    // Preview / readiness check
    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($fixtures['period']->fresh(), (int) $fixtures['company']->id);
    expect($preview->ready)->toBeTrue()
        ->and($preview->canGenerate)->toBeTrue()
        ->and($preview->readyEmployeeIds)->toContain((int) $fixtures['employee']->id)
        ->and($preview->missingTimesheetCount)->toBe(0);

    // Generate payroll
    $result = app(GenerateCrewPayroll::class)->handle($fixtures['period']->fresh());
    expect($result->errors)->toBe([]);

    $record = PayrollRecord::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->basic_salary)->toBeGreaterThan(0);
});

test('40. Test C — hybrid mode: skipped timeline with no replacement data reports missing timesheet and cannot generate', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);

    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company'], ['payroll.periods.update', 'payroll.periods.view']);
    grantTimelineSkipPermissions($approver, $fixtures['company'], ['payroll.periods.update', 'payroll.periods.view']);

    // Employee has blocking warning
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip Employee
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skipping employee without replacement data yet',
        ])
        ->assertRedirect();

    // Submit -> Approve -> Apply
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]));

    // No manual or import timesheet exists
    expect(CrewTimesheet::query()->where('period_id', $fixtures['period']->id)->where('employee_id', $fixtures['employee']->id)->exists())->toBeFalse();

    // Preview / readiness check
    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($fixtures['period']->fresh(), (int) $fixtures['company']->id);
    expect($preview->ready)->toBeTrue()
        ->and($preview->canGenerate)->toBeFalse()
        ->and($preview->readyCount)->toBe(0)
        ->and($preview->missingTimesheetEmployeeIds)->toContain((int) $fixtures['employee']->id)
        ->and($preview->missingTimesheetCount)->toBe(1);

    // Attempting generation must fail with ValidationException
    expect(fn () => app(GenerateCrewPayroll::class)->handle($fixtures['period']->fresh()))
        ->toThrow(ValidationException::class);
});

test('41. Test D — hybrid mode: skipped employee can be excluded and does not block other employees, skipping does not auto-exclude', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);

    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company'], ['payroll.periods.update', 'payroll.periods.view']);
    grantTimelineSkipPermissions($approver, $fixtures['company'], ['payroll.periods.update', 'payroll.periods.view']);

    // Employee 1 has blocking warning
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);

    // Employee 2 is valid
    ['employee' => $secondEmployee] = createSecondValidEmployee($fixtures);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip Employee 1
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skipping Employee 1',
        ])
        ->assertRedirect();

    // Verify skipping timeline data did NOT automatically mutate excluded_employee_ids!
    expect($fixtures['period']->fresh()->excluded_employee_ids ?? [])->toBe([]);

    // Submit -> Approve -> Apply
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]));

    // Employee 2 got a timesheet from Apply
    expect(CrewTimesheet::query()->where('period_id', $fixtures['period']->id)->where('employee_id', $secondEmployee->id)->exists())->toBeTrue();
    // Employee 1 got no timesheet
    expect(CrewTimesheet::query()->where('period_id', $fixtures['period']->id)->where('employee_id', $fixtures['employee']->id)->exists())->toBeFalse();

    // Now explicitly exclude Employee 1 in payroll period
    $fixtures['period']->update(['excluded_employee_ids' => [(int) $fixtures['employee']->id]]);

    // Preview / readiness check
    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($fixtures['period']->fresh(), (int) $fixtures['company']->id);
    expect($preview->ready)->toBeTrue()
        ->and($preview->canGenerate)->toBeTrue()
        ->and($preview->readyEmployeeIds)->toContain((int) $secondEmployee->id)
        ->and($preview->excludedEmployeeIds)->toContain((int) $fixtures['employee']->id)
        ->and($preview->missingTimesheetCount)->toBe(0);

    // Generation succeeds for Employee 2
    $result = app(GenerateCrewPayroll::class)->handle($fixtures['period']->fresh());
    expect($result->errors)->toBe([])
        ->and(PayrollRecord::query()->where('period_id', $fixtures['period']->id)->where('employee_id', $secondEmployee->id)->exists())->toBeTrue()
        ->and(PayrollRecord::query()->where('period_id', $fixtures['period']->id)->where('employee_id', $fixtures['employee']->id)->exists())->toBeFalse();
});

// -------------------------------------------------------------------------
// Exclusive Mode Behavior Tests (42)
// -------------------------------------------------------------------------

test('42. exclusive mode: skipped daily employee is not treated as covered by Crew Operations and blocks readiness', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    // In makeDailyCrewTimelineFixtures, period is already CrewOperations mode
    expect($fixtures['period']->requiresExclusiveCrewOperationsTimesheets())->toBeTrue();

    $approver = User::factory()->create(['company_id' => $fixtures['company']->id]);
    grantTimelineSkipPermissions($fixtures['user'], $fixtures['company'], ['payroll.periods.update', 'payroll.periods.view']);
    grantTimelineSkipPermissions($approver, $fixtures['company'], ['payroll.periods.update', 'payroll.periods.view']);

    // Employee has blocking warning
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', null, CrewPhaseStatus::Completed);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle($fixtures['period'], $fixtures['company']->id, $fixtures['user']->id);

    // Skip Employee
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$fixtures['period'], $preparation, $fixtures['employee']]), [
            'reason' => 'Skipping employee in exclusive mode',
        ])
        ->assertRedirect();

    // Submit -> Approve -> Apply
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.approve', [$fixtures['period'], $preparation]));
    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]));

    // Validate readiness via CrewOperationsPayrollGenerationGuard
    $guard = app(CrewOperationsPayrollGenerationGuard::class);
    $readiness = $guard->validateReadiness($fixtures['period']->fresh(), collect([$fixtures['employee']]), (int) $fixtures['company']->id);

    expect($readiness['ready'])->toBeFalse()
        ->and($readiness['blocking_reason'])->toContain("Daily crew employee {$fixtures['employee']->name} timeline data was skipped and is not covered by Crew Operations.");

    // And generation is blocked
    expect(fn () => $guard->assertReadyForGeneration($fixtures['period']->fresh(), collect([$fixtures['employee']]), (int) $fixtures['company']->id))
        ->toThrow(ValidationException::class);

    // But if excluded via excluded_employee_ids, the period is not blocked by that employee
    $fixtures['period']->update(['excluded_employee_ids' => [(int) $fixtures['employee']->id]]);
    $readinessWithExclusion = $guard->validateReadiness($fixtures['period']->fresh(), collect([]), (int) $fixtures['company']->id);
    expect($readinessWithExclusion['ready'])->toBeTrue();
});
