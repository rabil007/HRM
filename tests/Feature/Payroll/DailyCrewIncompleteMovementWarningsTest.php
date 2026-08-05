<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use App\Support\Payroll\CrewOvertimePay;
use App\Support\Payroll\CrewPayrollCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

test('warning-only incomplete timesheet remains ready and does not increase blockingCount', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'WARN-READY-1', 100, 50, 25);
    $employee->update(['name' => 'FRANKLINE MESAPE EBONG']);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'sign_on_standby_from' => null,
        'sign_on_standby_to' => '2026-07-05',
        'sign_on_standby_days' => 3,
        'overtime_hours' => 5,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);
    $payload = $preview->toPublicArray();

    expect($preview->ready)->toBeTrue()
        ->and($preview->canGenerate)->toBeTrue()
        ->and($preview->blockingCount)->toBe(0)
        ->and($preview->warningCount)->toBe(1)
        ->and($preview->readyEmployeeIds)->toContain($employee->id)
        ->and($preview->warningIssues[0]['code'])->toBe('incomplete_movement_range')
        ->and($payload)->toHaveKeys(['warning_issues', 'warning_count'])
        ->and($payload['warning_count'])->toBe(1)
        ->and($payload['warning_issues'])->toHaveCount(1);
});

test('generate payroll is allowed when only incomplete-movement warnings exist', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'WARN-GEN-1', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'sign_on_standby_from' => '2026-07-01',
        'sign_on_standby_to' => null,
        'sign_on_standby_days' => 4,
        'overtime_hours' => 8,
        'additional_amount' => 50,
        'deduction_amount' => 10,
    ]);

    $result = app(GenerateCrewPayroll::class)->handle($period);

    expect($result->generatedCount)->toBe(1)
        ->and($result->preview['warning_count'] ?? null)->toBe(1)
        ->and($result->preview['blocking_count'] ?? null)->toBe(0);

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->present_days)->toBe(0.0)
        ->and((float) $record->overtime_hours)->toBe(8.0)
        ->and((float) $record->bonus)->toBe(50.0)
        ->and((float) $record->other_deductions)->toBe(10.0)
        ->and((float) ($record->calculation_breakdown['sign_on_standby_days'] ?? -1))->toBe(0.0)
        ->and((float) ($record->calculation_breakdown['sign_on_standby_pay'] ?? -1))->toBe(0.0);
});

test('incomplete category is ignored while complete category and overtime still calculate', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'WARN-MIX-1', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'sign_on_standby_from' => null,
        'sign_on_standby_to' => '2026-07-05',
        'sign_on_standby_days' => 9,
        'onsite_from' => '2026-07-16',
        'onsite_to' => '2026-07-20',
        'onsite_days' => 5,
        'overtime_hours' => 5,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);
    expect($preview->canGenerate)->toBeTrue()
        ->and($preview->blockingCount)->toBe(0)
        ->and($preview->warningCount)->toBe(1);

    $result = app(GenerateCrewPayroll::class)->handle($period);
    expect($result->generatedCount)->toBe(1);

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->firstOrFail();

    expect((float) $record->calculation_breakdown['sign_on_standby_days'])->toBe(0.0)
        ->and((float) $record->calculation_breakdown['sign_on_standby_pay'])->toBe(0.0)
        ->and((float) $record->calculation_breakdown['onsite_days'])->toBe(5.0)
        ->and((float) $record->calculation_breakdown['onsite_pay'])->toBe(500.0)
        ->and((float) $record->present_days)->toBe(5.0)
        ->and((float) $record->overtime_hours)->toBe(5.0)
        ->and((float) $record->overtime_pay)->toBeGreaterThan(0);
});

test('overtime-only daily crew row calculates without movement dates', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'OT-ONLY-1', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'overtime_hours' => 10,
    ]);

    $result = app(GenerateCrewPayroll::class)->handle($period);
    $record = PayrollRecord::query()->where('period_id', $period->id)->firstOrFail();

    expect($result->generatedCount)->toBe(1)
        ->and((float) $record->present_days)->toBe(0.0)
        ->and((float) $record->overtime_hours)->toBe(10.0)
        ->and((float) $record->overtime_pay)->toBeGreaterThan(0);
});

test('addition-only and deduction-only daily crew rows calculate without movement dates', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $addition = createCrewEmployeeWithContract($company, 'ADD-ONLY-1', 100, 50, 25);
    $deduction = createCrewEmployeeWithContract($company, 'DED-ONLY-1', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $addition->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'additional_amount' => 250,
    ]);
    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $deduction->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'deduction_amount' => 75,
    ]);

    $result = app(GenerateCrewPayroll::class)->handle($period);

    expect($result->generatedCount)->toBe(2);

    $additionRecord = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $addition->id)
        ->firstOrFail();
    $deductionRecord = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $deduction->id)
        ->firstOrFail();

    expect((float) $additionRecord->bonus)->toBe(250.0)
        ->and((float) $additionRecord->gross_salary)->toBe(250.0)
        ->and((float) $additionRecord->present_days)->toBe(0.0)
        ->and((float) $deductionRecord->other_deductions)->toBe(75.0)
        ->and((float) $deductionRecord->net_salary)->toBe(-75.0)
        ->and((float) $deductionRecord->present_days)->toBe(0.0);
});

test('salary-input-only daily crew row follows existing recalculation path', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'SAL-ONLY-1', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 300,
    ]);

    $result = app(GenerateCrewPayroll::class)->handle($period);
    $record = PayrollRecord::query()->where('period_id', $period->id)->firstOrFail();

    expect($result->generatedCount)->toBe(1)
        ->and((float) $record->bonus)->toBe(300.0)
        ->and((float) $record->gross_salary)->toBe(300.0)
        ->and((float) $record->present_days)->toBe(0.0);
});

test('overtime without required basic daily rate remains blocked at calculation', function () {
    $timesheet = new CrewTimesheet([
        'overtime_hours' => 5,
        'sign_on_standby_days' => 0,
        'onsite_days' => 0,
    ]);

    $components = Collection::make([
        new ContractSalaryComponent([
            'component_code' => SalaryComponentCode::SiteAllowance,
            'component_name' => 'Site',
            'amount' => 50,
            'status' => SalaryComponentStatus::Active,
        ]),
    ]);

    expect(fn () => (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
    ))->toThrow(ValidationException::class);
});

test('missing historical contract remains blocking while incomplete warning on another employee is preserved', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $warningEmployee = createCrewEmployeeWithContract($company, 'WARN-OTHER-1', 100, 50, 25);
    $warningEmployee->update(['name' => 'ROMULO ELARDO MICU']);
    $blockingEmployee = createCrewEmployeeWithContract($company, 'BLOCK-CONTRACT-1', 100, 50, 25);
    $blockingEmployee->update(['name' => 'ANOOP PILLAI']);
    $blockingEmployee->contracts()->update([
        'start_date' => '2026-07-15',
        'end_date' => '2026-12-31',
    ]);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $warningEmployee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'sign_on_standby_from' => null,
        'sign_on_standby_to' => '2026-07-05',
        'overtime_hours' => 2,
    ]);

    $blockingTimesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $blockingEmployee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-05',
        'onsite_days' => 5,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $blockingTimesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-05',
        'days' => 5,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);

    expect($preview->ready)->toBeFalse()
        ->and($preview->canGenerate)->toBeFalse()
        ->and($preview->warningCount)->toBe(1)
        ->and($preview->warningIssues[0]['employee_id'])->toBe($warningEmployee->id)
        ->and($preview->blockingCount)->toBeGreaterThan(0)
        ->and(collect($preview->blockingIssues)->pluck('code'))->toContain('missing_historical_contract')
        ->and($preview->readyEmployeeIds)->toContain($warningEmployee->id)
        ->and($preview->readyEmployeeIds)->not->toContain($blockingEmployee->id);

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period))
        ->toThrow(ValidationException::class);
});

test('overlapping historical contracts remain blocking', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'OVERLAP-CT-1', 100, 50, 25);

    $existing = $employee->contracts()->first();
    $existing->update([
        'start_date' => '2020-01-01',
        'end_date' => null,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'start_date' => '2026-06-01',
        'end_date' => null,
        'basic_salary' => 100,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ]);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-03',
        'onsite_days' => 3,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-03',
        'days' => 3,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);

    expect($preview->blockingCount)->toBeGreaterThan(0)
        ->and(collect($preview->blockingIssues)->pluck('code'))->toContain('overlapping_historical_contracts');
});
