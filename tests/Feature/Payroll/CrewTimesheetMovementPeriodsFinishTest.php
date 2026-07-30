<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\ClearManualImportCrewTimesheets;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\Services\CrewPayrollSalarySheetExporter;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

test('manual save accepts two onsite periods and nulls parent from/to', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.view',
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'MP-2059', 100, 50, 25);
    $vesselA = makeCrewMovementVessel('Vessel A '.uniqid());
    $vesselB = makeCrewMovementVessel('Vessel B '.uniqid());

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'overtime_hours' => 4,
            'additional_amount' => 0,
            'deduction_amount' => 0,
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'vessel_id' => $vesselA->id,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-11',
                ],
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'vessel_id' => $vesselB->id,
                    'from_date' => '2026-07-20',
                    'to_date' => '2026-07-31',
                ],
            ],
        ])
        ->assertRedirect();

    $timesheet = CrewTimesheet::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('period_id', $period->id)
        ->with('segments')
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and($timesheet->source)->toBe(CrewTimesheetSource::Manual)
        ->and($timesheet->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved)
        ->and($timesheet->segments)->toHaveCount(2)
        ->and((float) $timesheet->onsite_days)->toBe(23.0)
        ->and($timesheet->onsite_from)->toBeNull()
        ->and($timesheet->onsite_to)->toBeNull()
        ->and((float) $timesheet->overtime_hours)->toBe(4.0);
});

test('overlapping manual movement periods are rejected while consecutive are accepted', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'MP-OVER', 100, 50, 25);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.show', $period))
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-11',
                ],
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-11',
                    'to_date' => '2026-07-20',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('segments.1.from_date');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-11',
                ],
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-12',
                    'to_date' => '2026-07-20',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(CrewTimesheet::query()->where('employee_id', $employee->id)->where('period_id', $period->id)->exists())->toBeTrue();
});

test('crew operations segments cannot be replaced by manual segments payload', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ]);

    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->firstOrFail();

    expect($timesheet->isOperationallyLocked())->toBeTrue();

    expect(fn () => app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period']->fresh(),
        $fixtures['employee'],
        [
            'overtime_hours' => 2,
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-05',
                ],
            ],
        ],
        $fixtures['user']->id,
    ))->toThrow(ValidationException::class);

    expect($timesheet->fresh()->segments->every(
        fn ($segment) => $segment->source === CrewTimesheetSource::CrewOperations,
    ))->toBeTrue();
});

test('clear timesheets soft-deletes manual segments and preserves crew operations segments', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.clear',
    ]);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    $ops = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->with('segments')
        ->firstOrFail();
    $opsSegmentIds = $ops->segments->pluck('id')->all();

    $manualEmployee = createCrewEmployeeWithContract($fixtures['company'], 'CLR-SEG-M', 100, 50, 25);
    $manual = CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $manualEmployee->id,
        'period_id' => $fixtures['period']->id,
        'source' => CrewTimesheetSource::Manual,
    ]);
    CrewTimesheetSegment::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_id' => $manual->id,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-10',
        'days' => 10,
    ]);

    $result = app(ClearManualImportCrewTimesheets::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    expect($result['cleared_count'])->toBe(1)
        ->and(CrewTimesheet::query()->find($manual->id))->toBeNull()
        ->and(CrewTimesheet::query()->find($ops->id))->not->toBeNull()
        ->and(CrewTimesheetSegment::query()->whereIn('id', $opsSegmentIds)->count())->toBe(count($opsSegmentIds));
});

test('multi-segment employee produces one payroll record with movement breakdown export', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.generate',
        'payroll.crew_timesheets.view',
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'MP-PAY', 100, 50, 25);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'approved_at' => now(),
        'approved_by' => $user->id,
        'onsite_from' => null,
        'onsite_to' => null,
        'onsite_days' => 23,
        'sign_on_standby_days' => 0,
        'sign_off_standby_days' => 0,
        'overtime_hours' => 0,
    ]);
    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-11',
        'days' => 11,
        'source' => CrewTimesheetSource::Manual,
    ]);
    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 2,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-20',
        'to_date' => '2026-07-31',
        'days' => 12,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $result = app(GenerateCrewPayroll::class)->handle($period->fresh());

    expect($result->generatedCount)->toBeGreaterThan(0)
        ->and(PayrollRecord::query()
            ->where('company_id', $company->id)
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->count())->toBe(1);

    $record = PayrollRecord::query()
        ->where('employee_id', $employee->id)
        ->where('period_id', $period->id)
        ->firstOrFail();

    expect($record->calculation_breakdown['movement_segments'] ?? null)->toHaveCount(2)
        ->and((float) ($record->calculation_breakdown['onsite_days'] ?? 0))->toBe(23.0);

    $period->update(['status' => PayrollPeriodStatus::Approved]);
    $export = app(CrewPayrollSalarySheetExporter::class)->export($company->id, $period->fresh());
    $spreadsheet = IOFactory::load($export['path']);
    $movement = $spreadsheet->getSheetByName('Movement Details');
    $salary = $spreadsheet->getSheet(0);

    expect($movement)->not->toBeNull()
        ->and($movement->getCell('F1')->getValue())->toBe('RANK')
        ->and($movement->getCell('A2')->getValue())->toBe('MP-PAY')
        ->and($movement->getCell('A3')->getValue())->toBe('MP-PAY');

    $salaryEmpNos = [];
    for ($row = 2; $row <= $salary->getHighestRow(); $row++) {
        if ($salary->getCell("B{$row}")->getValue() === 'MP-PAY') {
            $salaryEmpNos[] = 'MP-PAY';
        }
    }

    expect($salaryEmpNos)->toHaveCount(1);

    @unlink($export['path']);
});
