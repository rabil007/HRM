<?php

use App\Jobs\GeneratePayrollPayslipsJob;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\GeneratePayrollPayslips;
use App\Support\Payroll\Actions\GeneratePayslip;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('dispatch for period queues parallel jobs only for records without payslips', function () {
    Queue::fake();

    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create();
    $generatedEmployee = Employee::factory()->forCompany($company)->create();
    $pendingEmployee = Employee::factory()->forCompany($company)->create();

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $generatedEmployee->id,
        'period_id' => $period->id,
        'payslip_path' => 'payslips/'.$company->id.'/'.$period->id.'/generated.pdf',
    ]);

    $pendingRecord = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $pendingEmployee->id,
        'period_id' => $period->id,
        'payslip_path' => null,
    ]);

    app(GeneratePayrollPayslips::class)->dispatchForPeriod($period);

    Queue::assertPushed(GeneratePayrollPayslipsJob::class, 1);

    Queue::assertPushed(GeneratePayrollPayslipsJob::class, function (GeneratePayrollPayslipsJob $job) use ($company, $period, $pendingRecord): bool {
        return $job->companyId === $company->id
            && $job->periodId === $period->id
            && $job->recordIds === [$pendingRecord->id];
    });
});

test('dispatch for period splits large pay runs into multiple jobs', function () {
    Queue::fake();

    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create();

    foreach (range(1, 26) as $index) {
        $employee = Employee::factory()->forCompany($company)->create([
            'employee_no' => sprintf('PAY-%03d', $index),
        ]);

        PayrollRecord::factory()->for($company)->create([
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            'payslip_path' => null,
        ]);
    }

    app(GeneratePayrollPayslips::class)->dispatchForPeriod($period);

    Queue::assertPushed(GeneratePayrollPayslipsJob::class, 2);
});

test('handle skips records that already have payslip files', function () {
    Storage::fake('local');

    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create();
    $generatedEmployee = Employee::factory()->forCompany($company)->create();
    $pendingEmployee = Employee::factory()->forCompany($company)->create();

    $generatedRecord = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $generatedEmployee->id,
        'period_id' => $period->id,
        'payslip_path' => 'payslips/'.$company->id.'/'.$period->id.'/generated.pdf',
    ]);

    $pendingRecord = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $pendingEmployee->id,
        'period_id' => $period->id,
        'payslip_path' => null,
    ]);

    app(GeneratePayrollPayslips::class)->handle(
        $company->id,
        $period->id,
        [$generatedRecord->id, $pendingRecord->id],
        app(GeneratePayslip::class),
    );

    $generatedRecord->refresh();
    $pendingRecord->refresh();

    expect($generatedRecord->payslip_path)->toBe('payslips/'.$company->id.'/'.$period->id.'/generated.pdf')
        ->and($pendingRecord->payslip_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $pendingRecord->payslip_path))->toBeTrue();
});

test('regenerate for period clears existing payslips and queues jobs for all records', function () {
    Queue::fake();
    Storage::fake('local');

    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create();
    $generatedEmployee = Employee::factory()->forCompany($company)->create();
    $pendingEmployee = Employee::factory()->forCompany($company)->create();

    $generatedPath = 'payslips/'.$company->id.'/'.$period->id.'/generated.pdf';
    Storage::disk('local')->put($generatedPath, 'old-payslip');

    $generatedRecord = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $generatedEmployee->id,
        'period_id' => $period->id,
        'payslip_path' => $generatedPath,
    ]);

    $pendingRecord = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $pendingEmployee->id,
        'period_id' => $period->id,
        'payslip_path' => null,
    ]);

    $queued = app(GeneratePayrollPayslips::class)->regenerateForPeriod($period);

    $generatedRecord->refresh();
    $pendingRecord->refresh();

    expect($queued)->toBe(2)
        ->and($generatedRecord->payslip_path)->toBeNull()
        ->and($pendingRecord->payslip_path)->toBeNull()
        ->and(Storage::disk('local')->exists($generatedPath))->toBeFalse();

    Queue::assertPushed(GeneratePayrollPayslipsJob::class, 1);

    Queue::assertPushed(GeneratePayrollPayslipsJob::class, function (GeneratePayrollPayslipsJob $job) use ($company, $period, $generatedRecord, $pendingRecord): bool {
        return $job->companyId === $company->id
            && $job->periodId === $period->id
            && $job->recordIds === [$generatedRecord->id, $pendingRecord->id];
    });
});
