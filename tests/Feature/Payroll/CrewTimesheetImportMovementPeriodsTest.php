<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;

test('repeated daily crew excel rows create one parent with multiple segments', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $employee = createImportCrewEmployee($company, '2059', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-11',
            'overtime_hours' => 8,
        ],
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-07-20',
            'onsite_to' => '2026-07-31',
            'overtime_hours' => '',
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.valid', 2)
        ->assertJsonPath('summary.invalid', 0);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-11',
            'overtime_hours' => 8,
        ],
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-07-20',
            'onsite_to' => '2026-07-31',
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import', $period), [
            'file' => $file,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $timesheet = CrewTimesheet::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('period_id', $period->id)
        ->with('segments')
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and($timesheet->source)->toBe(CrewTimesheetSource::Import)
        ->and($timesheet->segments)->toHaveCount(2)
        ->and($timesheet->segments->where('pay_category', CrewTimesheetPayCategory::Onsite)->count())->toBe(2)
        ->and((float) $timesheet->onsite_days)->toBe(23.0)
        ->and($timesheet->onsite_from)->toBeNull()
        ->and($timesheet->onsite_to)->toBeNull()
        ->and((float) $timesheet->overtime_hours)->toBe(8.0);
});

test('repeated daily rows reject overlapping ranges and duplicated overtime with excel row numbers', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    createImportCrewEmployee($company, '2059', 50, 661, 611);

    $overlapFile = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-15',
        ],
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-07-10',
            'onsite_to' => '2026-07-20',
        ],
    ]);

    $overlapPreview = $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $overlapFile,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 2)
        ->json();

    $overlapMessages = collect($overlapPreview['errors'])->pluck('message')->implode(' ');
    expect($overlapMessages)->toContain('Excel rows')
        ->and($overlapMessages)->toContain('2')
        ->and($overlapMessages)->toContain('3');

    $otFile = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-11',
            'overtime_hours' => 5,
        ],
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-07-20',
            'onsite_to' => '2026-07-31',
            'overtime_hours' => 3,
        ],
    ]);

    $otPreview = $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $otFile,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 2)
        ->json();

    $otMessages = collect($otPreview['errors'])->pluck('message')->implode(' ');
    expect($otMessages)->toContain('employee-level')
        ->and($otMessages)->toContain('once per movement')
        ->and($otMessages)->toContain('Excel rows');
});

test('repeated monthly crew excel rows remain invalid', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $employee = createImportCrewEmployee($company, '3001', 50, 661, 611);
    $employee->currentContract?->update([
        'salary_structure' => ContractSalaryStructure::Monthly,
    ]);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '3001',
            'name' => 'Monthly Crew',
            'unpaid_leave_days' => 1,
        ],
        [
            'employee_no' => '3001',
            'name' => 'Monthly Crew',
            'unpaid_leave_days' => 2,
        ],
    ]);

    $preview = $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertOk()
        ->json();

    $messages = collect($preview['errors'])->pluck('message')->implode(' ');

    expect($preview['summary']['invalid'])->toBeGreaterThan(0)
        ->and($messages)->toContain('Duplicate employee number');
});
