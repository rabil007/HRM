<?php

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\CrewTimesheet;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Contracts\Actions\UpsertEmployeeContract;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\GeneratePayslip;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Carbon::setTestNow('2026-06-30');
    Storage::fake('local');
});

afterEach(function () {
    Carbon::setTestNow();
});

dataset('monthly crew payslip snapshots', [
    'Vinod full month' => [0, false, 875.0, 55000.0],
    'three unpaid days' => [3, false, 787.5, 49500.0],
    'actual deduction' => [0, false, 875.0, 55000.0, 200.0],
    'historical non-payable provision' => [0, true, 0.0, 54125.0],
]);

test('monthly crew HTML preview renders one snapshot EOS earning and reconciled totals', function (int $unpaidDays, bool $legacy, float $eos, float $gross, float $deductions = 0.0) {
    [$company, $record] = monthlyCrewPayslipRenderingFixture($unpaidDays, $legacy, $deductions);
    $before = $record->fresh()->getRawOriginal();
    $response = $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.show', ['payrollRecord' => $record, 'view' => 'html']))
        ->assertOk();
    $html = $response->getContent();
    $rows = monthlyCrewRenderedRows($html);
    $label = $unpaidDays ? 'EOS (Monthly, prorated)' : 'EOS (Monthly)';
    $total = array_pop($rows);

    expect($total)->toBe(['Total Earnings', number_format($gross, 2, '.', '')])
        ->and(array_sum(array_map(fn (array $row): float => (float) str_replace(',', '', $row[1]), $rows)))->toBe($gross)
        ->and(collect($rows)->filter(fn (array $row): bool => str_starts_with($row[0], 'EOS (Monthly'))->count())->toBe($legacy ? 0 : 1)
        ->and($html)->toContain(number_format($gross - $deductions, 2, '.', '').' AED')
        ->and($record->fresh()->getRawOriginal())->toBe($before);

    if ($deductions > 0) {
        $deductionRows = monthlyCrewRenderedRows($html, 2);
        expect(array_pop($deductionRows))->toBe(['Total Deductions', number_format($deductions, 2, '.', '')])
            ->and(array_sum(array_map(fn (array $row): float => (float) str_replace(',', '', $row[1]), $deductionRows)))->toBe($deductions);
    }

    if ($legacy) {
        expect($html)->toContain('Non-Payable Provision', '875.00 AED');
    } else {
        expect($rows)->toContain([$label, number_format($eos, 2, '.', '')])
            ->and($html)->not->toContain('Non-Payable Provision');
    }
})->with('monthly crew payslip snapshots');

test('monthly crew inline downloaded and stored PDFs match preview snapshot earnings', function (int $unpaidDays, bool $legacy, float $eos, float $gross, float $deductions = 0.0) {
    [$company, $record] = monthlyCrewPayslipRenderingFixture($unpaidDays, $legacy, $deductions);
    $before = $record->fresh()->getRawOriginal();
    $this->withSession(['current_company_id' => $company->id]);
    $preview = $this->get(route('payroll.payslips.show', ['payrollRecord' => $record, 'view' => 'html']))->assertOk();
    $earnings = monthlyCrewRenderedRows($preview->getContent());
    $deductionRows = $deductions > 0 ? monthlyCrewRenderedRows($preview->getContent(), 2) : [];
    $inline = $this->get(route('payroll.payslips.show', $record))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $download = $this->get(route('payroll.payslips.download', $record))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($record->fresh()->getRawOriginal())->toBe($before);

    app(GeneratePayslip::class)->handle($record);
    $storedDownload = $this->get(route('payroll.payslips.download', $record))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $storedInline = $this->get(route('payroll.payslips.show', $record))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $storedBytes = Storage::disk('local')->get($record->fresh()->payslip_path);
    expect($storedDownload->streamedContent())->toBe($storedBytes)
        ->and(file_get_contents($storedInline->baseResponse->getFile()->getPathname()))->toBe($storedBytes);

    foreach ([$inline->getContent(), $download->streamedContent(), $storedBytes] as $bytes) {
        expect($bytes)->toStartWith('%PDF-');
        $path = tempnam(sys_get_temp_dir(), 'monthly-eos-payslip-');
        try {
            file_put_contents($path, $bytes);
            $process = new Process(['pdftotext', '-layout', $path, '-']);
            $process->mustRun();
            $text = $process->getOutput();
            foreach ([...$earnings, ...$deductionRows] as [$label, $amount]) {
                expect($text)->toMatch('/'.preg_quote($label, '/').'\s+'.preg_quote($amount, '/').'/');
            }
            expect($text)->toMatch('/Net Salary\s+'.preg_quote(number_format($gross - $deductions, 2, '.', ''), '/').' AED/')
                ->and(substr_count($text, 'EOS (Monthly'))->toBe($legacy ? 0 : 1)
                ->and($text)->toContain($company->name);
            if ($legacy) {
                expect($text)->toContain('Non-Payable Provision', '875.00 AED');
            } else {
                expect($text)->not->toContain('Non-Payable Provision');
            }
        } finally {
            @unlink($path);
        }
    }
})->with('monthly crew payslip snapshots')
    ->skip(fn (): bool => (new ExecutableFinder)->find('pdftotext') === null, 'PDF text verification requires Poppler pdftotext.');

/** @return array{Company, PayrollRecord} */
function monthlyCrewPayslipRenderingFixture(int $unpaidDays, bool $legacy, float $deductions): array
{
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $currency = Currency::query()->firstOrCreate(['code' => 'AED'], [
        'name' => 'UAE Dirham', 'symbol' => 'AED', 'is_active' => true,
    ]);
    $company->update(['currency_id' => $currency->id]);
    test()->actingAs($user);
    grantCompanyPermissions($user, $company, ['payroll.records.view']);
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['name' => 'Vinod', 'status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01', 'status' => 'active', 'payroll_category' => 'crew', 'salary_structure' => 'monthly',
        'basic_salary' => 15000, 'housing_allowance' => 10000, 'transport_allowance' => 5000,
        'other_allowances' => 24125, 'eos_monthly_amount' => 875,
    ]);
    $period = PayrollPeriod::factory()->for($company)->create(['name' => 'June 2026', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30']);
    if ($legacy) {
        $record = PayrollRecord::factory()->for($company)->create([
            'employee_id' => $employee->id, 'period_id' => $period->id, 'payroll_category' => PayrollCategory::Crew,
            'basic_salary' => 15000, 'housing_allowance' => 10000, 'transport_allowance' => 5000, 'other_allowances' => 24125,
            'overtime_pay' => 0, 'bonus' => 0, 'total_deductions' => 0, 'other_deductions' => 0,
            'gross_salary' => 54125, 'net_salary' => 54125, 'status' => 'paid',
            'calculation_breakdown' => ['salary_structure' => 'monthly', 'eos_monthly_provision' => 875, 'currency_code' => 'AED'],
        ]);
    } else {
        CrewTimesheet::factory()->for($company)->create([
            'employee_id' => $employee->id, 'period_id' => $period->id,
            'unpaid_leave_days' => $unpaidDays, 'additional_amount' => 0, 'deduction_amount' => $deductions,
        ]);
        app(GenerateCrewPayroll::class)->handle($period);
        $record = PayrollRecord::query()->where('period_id', $period->id)->sole();
        $record->update(['status' => 'approved']);
    }
    // Rendering must never use the live contract to recalculate EOS or other earnings.
    $contract->update(['eos_monthly_amount' => 9999, 'other_allowances' => 99999]);

    return [$company, $record];
}

/** @return list<array{string, string}> */
function monthlyCrewRenderedRows(string $html, int $table = 1): array
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML($html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($document);
    $rows = [];
    foreach ($xpath->query('(//table[@class="data-table"])['.$table.']/tbody/tr') as $row) {
        $cells = $xpath->query('./td', $row);
        $rows[] = [trim($cells->item(0)->textContent), trim($cells->item(1)->textContent)];
    }

    return $rows;
}
