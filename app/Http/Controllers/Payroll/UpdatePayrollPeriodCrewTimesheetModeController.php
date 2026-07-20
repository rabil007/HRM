<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\CrewTimesheetMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\UpdatePayrollPeriodCrewTimesheetModeRequest;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\UpdatePayrollPeriodCrewTimesheetMode;
use Illuminate\Http\RedirectResponse;

class UpdatePayrollPeriodCrewTimesheetModeController extends Controller
{
    public function __invoke(
        UpdatePayrollPeriodCrewTimesheetModeRequest $request,
        PayrollPeriod $payrollPeriod,
        UpdatePayrollPeriodCrewTimesheetMode $updateMode,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $updateMode->handle(
            $payrollPeriod,
            $companyId,
            CrewTimesheetMode::from($request->validated('crew_timesheet_mode')),
        );

        return back()->with('success', 'Timesheet source updated.');
    }
}
