<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\BuildCrewPayrollCoverageSummary;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use Illuminate\Support\Facades\DB;

test('crew payroll generation preview query count stays bounded as employees grow', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    foreach (range(1, 5) as $index) {
        $employee = createCrewEmployeeWithContract($company, "PERF-S-{$index}", 100, 50, 25);
        CrewTimesheet::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            'source' => CrewTimesheetSource::Manual,
            'approval_status' => CrewTimesheetApprovalStatus::Approved,
            'onsite_days' => 10,
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-10',
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);
    $smallCount = count(DB::getQueryLog());

    foreach (range(6, 25) as $index) {
        $employee = createCrewEmployeeWithContract($company, "PERF-L-{$index}", 100, 50, 25);
        CrewTimesheet::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            'source' => CrewTimesheetSource::Manual,
            'approval_status' => CrewTimesheetApprovalStatus::Approved,
            'onsite_days' => 8,
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-08',
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);
    $largeCount = count(DB::getQueryLog());

    expect($smallCount)->toBeLessThan(20)
        ->and($largeCount)->toBeLessThan(20)
        ->and($largeCount)->toBeLessThanOrEqual($smallCount + 3);
});

test('crew payroll coverage summary query count stays bounded', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    foreach (range(1, 20) as $index) {
        $employee = createCrewEmployeeWithContract($company, "COV-{$index}", 100, 50, 25);
        if ($index % 3 === 0) {
            continue;
        }

        CrewTimesheet::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            'source' => CrewTimesheetSource::Manual,
            'approval_status' => $index % 2 === 0
                ? CrewTimesheetApprovalStatus::Approved
                : CrewTimesheetApprovalStatus::Draft,
            'onsite_days' => 5,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $summary = app(BuildCrewPayrollCoverageSummary::class)->handle($period, (int) $company->id);
    $queryCount = count(DB::getQueryLog());

    expect($queryCount)->toBeLessThan(15)
        ->and($summary['missing_timesheet_count'])->toBeGreaterThan(0)
        ->and($summary['awaiting_approval_count'])->toBeGreaterThan(0);
});

test('generate crew payroll does not multiply read queries per employee', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    foreach (range(1, 12) as $index) {
        $employee = createCrewEmployeeWithContract($company, "GEN-PERF-{$index}", 100, 50, 25);
        CrewTimesheet::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            'source' => CrewTimesheetSource::Manual,
            'approval_status' => CrewTimesheetApprovalStatus::Approved,
            'onsite_days' => 10,
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-10',
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = app(GenerateCrewPayroll::class)->handle($period);
    $queries = collect(DB::getQueryLog());
    $queryCount = $queries->count();

    expect($result->generatedCount)->toBe(12)
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(12)
        ->and($queryCount)->toBeLessThan(80);

    $selectQueries = $queries->filter(fn (array $query): bool => str_starts_with(strtolower(trim($query['query'])), 'select'));
    expect($selectQueries->count())->toBeLessThan(40);
});

test('generation preview public payload omits employee id arrays', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    createCrewEmployeeWithContract($company, 'PUB-1', 100, 50, 25);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('payroll.generation-preview', $period))
        ->assertOk()
        ->assertJsonMissingPath('ready_employee_ids')
        ->assertJsonMissingPath('missing_timesheet_employee_ids')
        ->assertJsonPath('missing_timesheet_count', 1);
});
