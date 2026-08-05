<?php

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;

test('fractional segment days are rejected on update segments request', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'FRAC-DAYS', 100, 0, 0);
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('payroll.timesheets.segments', [$period, $timesheet]), [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-01',
                    'days' => 1.5,
                ],
            ],
        ])
        ->assertSessionHasErrors('segments.0.days');
});
