<?php

namespace App\Http\Requests\Organization\Payroll\Concerns;

use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Employees\EmployeeVisibilityScope;

trait AssertsOwnedVisibleCrewTimesheetRoute
{
    /**
     * Assert the bound period/timesheet belong to the current company and that
     * the actor may access the timesheet employee under EmployeeVisibilityScope.
     * Inaccessible resources abort 404 so hidden employees are not confirmed.
     */
    protected function assertOwnedVisibleCrewTimesheetRoute(): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        if ($companyId <= 0) {
            abort(404);
        }

        $period = $this->route('payrollPeriod');
        $timesheet = $this->route('timesheet');

        if (! $period instanceof PayrollPeriod || ! $timesheet instanceof CrewTimesheet) {
            abort(404);
        }

        if ((int) $period->company_id !== $companyId
            || ! $period->isCrew()
            || (int) $timesheet->company_id !== $companyId
            || (int) $timesheet->period_id !== (int) $period->id) {
            abort(404);
        }

        $timesheet->loadMissing('employee');
        $employee = $timesheet->employee;

        if (! $employee instanceof Employee || (int) $employee->company_id !== $companyId) {
            abort(404);
        }

        abort_unless(
            EmployeeVisibilityScope::canAccess($this->user(), $employee, $companyId),
            404,
        );
    }
}
