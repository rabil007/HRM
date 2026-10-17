<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Bank;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Contracts\Actions\UpsertEmployeeContract;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\RecalculateCrewPayroll;
use App\Support\Payroll\ApplyMonthlyCrewSalaryInputs;
use App\Support\Payroll\PayrollRecordResource;
use App\Support\Payroll\PayslipData;
use App\Support\Payroll\Services\CrewPayrollSalarySheetExporter;
use App\Support\Payroll\Wps\WpsExcelExporter;
use App\Support\Payroll\Wps\WpsExportRows;
use App\Support\Payroll\Wps\WpsExportValidator;
use App\Support\Payroll\Wps\WpsSifExporter;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    Carbon::setTestNow('2026-06-30');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('monthly EOS flows once through generation recalculation payslip salary sheet and WPS', function (int $unpaidDays, string $gross, float $eos, float $loanAmount, float $salaryBonus = 100.0) {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01', 'status' => 'active',
        'payroll_category' => 'crew', 'salary_structure' => 'monthly',
        'basic_salary' => 15000, 'housing_allowance' => 10000,
        'transport_allowance' => 5000, 'other_allowances' => 24125,
        'eos_monthly_amount' => 875, 'labor_contract_id' => '12345678901234',
    ]);
    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30',
        'status' => PayrollPeriodStatus::Draft,
    ]);
    CrewTimesheet::factory()->for($company)->create([
        'period_id' => $period->id, 'employee_id' => $employee->id,
        'unpaid_leave_days' => $unpaidDays, 'additional_amount' => 125, 'deduction_amount' => 50,
    ]);
    SalaryInput::factory()->for($company)->create([
        'period_id' => $period->id, 'employee_id' => $employee->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'), 'amount' => $salaryBonus,
    ]);
    SalaryInput::factory()->for($company)->create([
        'period_id' => $period->id, 'employee_id' => $employee->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'loan'), 'amount' => $loanAmount,
    ]);

    $generator = app(GenerateCrewPayroll::class);
    expect($generator->handle($period)->generatedCount)->toBe(1);
    // Regeneration replaces the base snapshot rather than accumulating EOS or inputs.
    expect($generator->handle($period->fresh())->generatedCount)->toBe(1);
    $record = PayrollRecord::query()->where('period_id', $period->id)->sole();
    expect($record->gross_salary)->toBe($gross)
        ->and((float) $record->net_salary)->toBe((float) $gross - 50 - $loanAmount)
        ->and($record->calculation_breakdown['eos_monthly_paid'])->toEqual($eos);

    // Changing the live contract must never affect salary-input recalculation.
    $contract->update(['eos_monthly_amount' => 9999, 'other_allowances' => 99999]);
    app(RecalculateCrewPayroll::class)->handle($period->fresh());
    app(RecalculateCrewPayroll::class)->handle($period->fresh());
    $record->refresh();
    expect($record->gross_salary)->toBe($gross)
        ->and((float) $record->net_salary)->toBe((float) $gross - 50 - $loanAmount)
        ->and(PayrollRecordResource::toArray($record)['eos_monthly_paid'])->toBe(number_format($eos, 2, '.', ''));

    $payslip = PayslipData::for($record, $company->id);
    $sum = fn (array $rows) => array_sum(array_map(fn ($row) => (float) str_replace(',', '', $row['amount']), $rows));
    expect($sum($payslip['earnings']))->toBe((float) $gross)
        ->and($sum($payslip['deductions']))->toBe(50 + $loanAmount)
        ->and(collect($payslip['earnings'])->firstWhere('label', $unpaidDays ? 'EOS (Monthly, prorated)' : 'EOS (Monthly)')['amount'])
        ->toBe(number_format($eos, 2))
        ->and(view('payroll.payslip', $payslip)->render())->not->toContain('Non-Payable Provision');

    $record->update(['status' => 'approved']);
    $period->update(['status' => PayrollPeriodStatus::Approved]);
    $export = app(CrewPayrollSalarySheetExporter::class)->export($company->id, $period->fresh());
    try {
        $sheet = IOFactory::load($export['path'])->getSheetByName('Monthly Crew');
        expect($sheet->getCell('J1')->getValue())->toBe('EOS (MONTHLY PAID)')
            ->and((float) $sheet->getCell('J2')->getValue())->toBe($eos)
            ->and((float) $sheet->getCell('G2')->getValue())->toBe((float) $gross)
            ->and((float) $sheet->getCell('K2')->getValue())->toBe((float) $gross - ($unpaidDays ? 49500.0 : 55000.0));
    } finally {
        @unlink($export['path']);
    }

    $company->update(['wps_mol_uid' => 'MOL-12345', 'wps_agent_code' => 'AGENT-001', 'wps_employer_iban' => 'AE070331234567890123456']);
    $bank = Bank::query()->create(['name' => 'EOS Bank', 'uae_routing_code_agent_id' => '987654', 'is_active' => true]);
    EmployeeBankAccount::query()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id,
        'bank_id' => $bank->id, 'iban' => 'AE070331234567890123456',
        'account_name' => $employee->name, 'is_primary' => true,
    ]);
    $record = $record->fresh();
    $partition = app(WpsExportValidator::class)->partition($company, $period, collect([$record]));
    expect($partition['skipped'])->toBe([])->and($partition['eligible'])->toHaveCount(1);
    $rows = new WpsExportRows($company, $period, collect([$record]), 'EOS');
    $edr = $rows->edrRowsForExcel()[0];
    expect((float) $edr[7] + (float) $edr[8])->toBe(round((float) $record->net_salary));
    $path = tempnam(sys_get_temp_dir(), 'eos-wps-');
    try {
        file_put_contents($path, app(WpsExcelExporter::class)->export($company, $period, collect([$record]), 'EOS'));
        $wpsSheet = IOFactory::load($path)->getActiveSheet();
        expect((float) $wpsSheet->getCell('H1')->getValue() + (float) $wpsSheet->getCell('I1')->getValue())
            ->toBe((float) round((float) $record->net_salary))
            ->and((float) $wpsSheet->getCell('H2')->getValue())->toBe((float) round((float) $record->net_salary));
    } finally {
        @unlink($path);
    }
    $sif = app(WpsSifExporter::class)->export($company, $period, collect([$record]), 'EOS');
    $edr = explode(',', explode(PHP_EOL, trim($sif))[1]);
    expect((float) $edr[7] + (float) $edr[8])->toBe((float) $record->net_salary)
        ->and((float) $edr[8])->toBe(max((float) $record->net_salary - ($unpaidDays ? 49500.0 : 55000.0), 0.0));

    $breakdown = $record->calculation_breakdown;
    $breakdown['rates']['basic_monthly'] = 0;
    $record->calculation_breakdown = $breakdown;
    $invalid = app(WpsExportValidator::class)->partition($company, $period, collect([$record]));
    expect($invalid['eligible'])->toHaveCount(0)
        ->and($invalid['skipped'][0]['reason'])->toBe('Active basic monthly salary is missing.');
})->with([
    'full month' => [0, '55225.00', 875.0, 200.0],
    'prorated month' => [3, '49725.00', 787.5, 200.0],
    'positive variable income' => [0, '55225.00', 875.0, 0.0],
    'fractional AED' => [0, '55225.25', 875.0, 0.0, 100.25],
]);

test('future EOS revision changes only newly generated payroll for its effective month', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01', 'status' => 'active', 'payroll_category' => 'crew',
        'salary_structure' => 'monthly', 'basic_salary' => 5000, 'eos_monthly_amount' => 300,
    ]);
    app(ApplyContractSalaryRevision::class)->handle($contract, ['basic_salary' => 5000, 'eos_monthly_amount' => 350], '2026-07-01');
    expect((float) $contract->fresh()->eos_monthly_amount)->toBe(300.0);
    foreach (['2026-06-01' => 5300.0, '2026-07-01' => 5350.0] as $start => $expected) {
        $period = PayrollPeriod::factory()->for($company)->create([
            'start_date' => $start, 'end_date' => Carbon::parse($start)->endOfMonth()->toDateString(),
        ]);
        app(GenerateCrewPayroll::class)->handle($period, acknowledgeFuturePayableDays: true);
        expect((float) PayrollRecord::query()->where('period_id', $period->id)->sole()->net_salary)->toBe($expected);
    }
});

test('historical EOS provision stays non payable during salary input recalculation', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create(['status' => PayrollPeriodStatus::Processing]);
    $employee = Employee::factory()->forCompany($company)->create();
    $record = PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id, 'employee_id' => $employee->id, 'payroll_category' => PayrollCategory::Crew,
        'basic_salary' => 15000, 'housing_allowance' => 10000, 'transport_allowance' => 5000,
        'other_allowances' => 24125, 'overtime_pay' => 0, 'bonus' => 0,
        'gross_salary' => 54125, 'net_salary' => 54125, 'other_deductions' => 0, 'total_deductions' => 0,
        'calculation_breakdown' => ['salary_structure' => 'monthly', 'eos_monthly_provision' => 875],
    ]);
    app(RecalculateCrewPayroll::class)->handle($period);
    app(RecalculateCrewPayroll::class)->handle($period);
    $record->refresh();
    expect($record->net_salary)->toBe('54125.00')
        ->and($record->calculation_breakdown)->not->toHaveKey('eos_monthly_paid');
    $payslip = PayslipData::for($record, $company->id);
    expect($payslip['eos_monthly_provision'])->toBe('875.00')
        ->and(collect($payslip['earnings'])->pluck('label')->all())->not->toContain('EOS (Monthly)')
        ->and(view('payroll.payslip', $payslip)->render())->toContain('Non-Payable Provision');
});

test('finalized historical monthly payroll cannot be regenerated or recalculated', function (PayrollPeriodStatus $status) {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create(['status' => $status]);
    $employee = Employee::factory()->forCompany($company)->create();
    $record = PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id, 'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew, 'status' => $status->value,
        'gross_salary' => 54125, 'net_salary' => 54125,
        'calculation_breakdown' => ['salary_structure' => 'monthly', 'eos_monthly_provision' => 875],
    ]);
    $before = $record->fresh()->getRawOriginal();
    expect(fn () => app(GenerateCrewPayroll::class)->handle($period))->toThrow(ValidationException::class)
        ->and(fn () => app(RecalculateCrewPayroll::class)->handle($period))->toThrow(ValidationException::class)
        ->and($record->fresh()->getRawOriginal())->toBe($before);
})->with([PayrollPeriodStatus::Approved, PayrollPeriodStatus::Paid]);

test('monthly paid EOS survives recalculation of a snapshot without a stored base', function () {
    $record = new PayrollRecord([
        'basic_salary' => 15000, 'housing_allowance' => 10000, 'transport_allowance' => 5000,
        'other_allowances' => 24125, 'overtime_pay' => 0, 'bonus' => 0,
        'gross_salary' => 55000, 'net_salary' => 55000, 'other_deductions' => 0,
        'calculation_breakdown' => ['salary_structure' => 'monthly', 'eos_monthly_paid' => 875],
    ]);
    $action = app(ApplyMonthlyCrewSalaryInputs::class);
    $record->fill($action->apply($record, collect()));
    $record->fill($action->apply($record, collect()));
    expect($record->gross_salary)->toBe('55000.00')->and($record->net_salary)->toBe('55000.00');
});
