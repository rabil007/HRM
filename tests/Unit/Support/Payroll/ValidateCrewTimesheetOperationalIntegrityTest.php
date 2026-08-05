<?php

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\PayrollPeriod;
use App\Support\Payroll\ValidateCrewTimesheetOperationalIntegrity;

beforeEach(function () {
    ['company' => $this->company] = makePayrollFixtures();
    $this->period = PayrollPeriod::factory()->for($this->company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $this->employee = createCrewEmployeeWithContract($this->company, 'INT-1', 100, 50, 25);
    $this->employee->update(['name' => 'FRANKLINE MESAPE EBONG']);
    $this->validator = app(ValidateCrewTimesheetOperationalIntegrity::class);
});

test('sign-on start without end becomes a warning', function () {
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'period_id' => $this->period->id,
        'source' => CrewTimesheetSource::Manual,
        'sign_on_standby_from' => '2026-07-01',
        'sign_on_standby_to' => null,
        'sign_on_standby_days' => 3,
    ]);

    $result = $this->validator->handle($timesheet, $this->employee->fresh());

    expect($result->hasBlocking())->toBeFalse()
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['code'])->toBe('incomplete_movement_range')
        ->and($result->warnings[0]['pay_category'])->toBe('sign_on_standby')
        ->and($result->warnings[0]['message'])->toContain('incomplete Sign-On Standby dates')
        ->and($result->warnings[0]['message'])->toContain('ignored');
});

test('sign-on end without start becomes a warning', function () {
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'period_id' => $this->period->id,
        'source' => CrewTimesheetSource::Manual,
        'sign_on_standby_from' => null,
        'sign_on_standby_to' => '2026-07-05',
        'sign_on_standby_days' => 3,
    ]);

    $result = $this->validator->handle($timesheet, $this->employee->fresh());

    expect($result->hasBlocking())->toBeFalse()
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['message'])->toContain('incomplete Sign-On Standby dates')
        ->and($result->warnings[0]['message'])->toContain('Both start and end dates are needed');
});

test('onsite incomplete pair becomes a warning', function () {
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'period_id' => $this->period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_from' => '2026-07-10',
        'onsite_to' => null,
    ]);

    $result = $this->validator->handle($timesheet, $this->employee->fresh());

    expect($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['pay_category'])->toBe('onsite')
        ->and($result->hasBlocking())->toBeFalse();
});

test('sign-off incomplete pair becomes a warning', function () {
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'period_id' => $this->period->id,
        'source' => CrewTimesheetSource::Manual,
        'sign_off_standby_from' => null,
        'sign_off_standby_to' => '2026-07-20',
    ]);

    $result = $this->validator->handle($timesheet, $this->employee->fresh());

    expect($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['pay_category'])->toBe('sign_off_standby')
        ->and($result->hasBlocking())->toBeFalse();
});

test('returns all incomplete flat-field warnings together', function () {
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'period_id' => $this->period->id,
        'source' => CrewTimesheetSource::Manual,
        'sign_on_standby_from' => '2026-07-01',
        'sign_on_standby_to' => null,
        'onsite_from' => null,
        'onsite_to' => '2026-07-10',
        'sign_off_standby_from' => '2026-07-20',
        'sign_off_standby_to' => null,
    ]);

    $result = $this->validator->handle($timesheet, $this->employee->fresh());

    expect($result->hasBlocking())->toBeFalse()
        ->and($result->warnings)->toHaveCount(3);
});

test('reversed complete date range remains blocking', function () {
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'period_id' => $this->period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_from' => '2026-07-10',
        'onsite_to' => '2026-07-01',
        'onsite_days' => 5,
    ]);

    $result = $this->validator->handle($timesheet, $this->employee->fresh());

    expect($result->hasBlocking())->toBeTrue()
        ->and($result->blocking[0]['code'])->toBe('invalid_movement_range');
});

test('negative movement days remain blocking', function () {
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'period_id' => $this->period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-05',
        'onsite_days' => -2,
    ]);

    $result = $this->validator->handle($timesheet, $this->employee->fresh());

    expect($result->hasBlocking())->toBeTrue()
        ->and($result->blocking[0]['code'])->toBe('negative_movement_days');
});

test('complete overlapping movement ranges remain blocking', function () {
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'period_id' => $this->period->id,
        'source' => CrewTimesheetSource::Manual,
        'sign_on_standby_from' => '2026-07-01',
        'sign_on_standby_to' => '2026-07-05',
        'sign_on_standby_days' => 5,
        'onsite_from' => '2026-07-04',
        'onsite_to' => '2026-07-08',
        'onsite_days' => 5,
    ]);

    $result = $this->validator->handle($timesheet, $this->employee->fresh());

    expect($result->hasBlocking())->toBeTrue()
        ->and($result->blocking[0]['code'])->toBe('overlapping_movement_ranges');
});

test('malformed segment with missing dates remains blocking', function () {
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'period_id' => $this->period->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    // DB columns are non-null; assert the validator still treats incomplete
    // in-memory/legacy segment payloads as blocking (never downgraded to warnings).
    $segment = new CrewTimesheetSegment([
        'company_id' => $this->company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => null,
        'to_date' => '2026-07-05',
        'days' => 5,
    ]);
    $timesheet->setRelation('segments', collect([$segment]));

    $result = $this->validator->handle($timesheet, $this->employee->fresh());

    expect($result->hasBlocking())->toBeTrue()
        ->and($result->hasWarnings())->toBeFalse()
        ->and($result->blocking[0]['code'])->toBe('missing_segment_dates');
});

test('warning and blocking issues can appear together on flat fields', function () {
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'period_id' => $this->period->id,
        'source' => CrewTimesheetSource::Manual,
        'sign_on_standby_from' => '2026-07-01',
        'sign_on_standby_to' => null,
        'onsite_from' => '2026-07-10',
        'onsite_to' => '2026-07-01',
        'onsite_days' => 5,
    ]);

    $result = $this->validator->handle($timesheet, $this->employee->fresh());

    expect($result->warnings)->toHaveCount(1)
        ->and($result->blocking)->toHaveCount(1)
        ->and($result->warnings[0]['code'])->toBe('incomplete_movement_range')
        ->and($result->blocking[0]['code'])->toBe('invalid_movement_range');
});
