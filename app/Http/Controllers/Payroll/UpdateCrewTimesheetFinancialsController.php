<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\UpdateCrewTimesheetFinancialsRequest;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\UpdateCrewTimesheetFinancials;
use Illuminate\Http\RedirectResponse;

class UpdateCrewTimesheetFinancialsController extends Controller
{
    public function __invoke(
        UpdateCrewTimesheetFinancialsRequest $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheet $timesheet,
        UpdateCrewTimesheetFinancials $updateCrewTimesheetFinancials,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        abort_unless($payrollPeriod->isCrew(), 404);
        abort_unless((int) $timesheet->company_id === $companyId, 404);
        abort_unless((int) $timesheet->period_id === (int) $payrollPeriod->id, 404);

        $updateCrewTimesheetFinancials->handle(
            $payrollPeriod,
            $timesheet,
            $request->financialData(),
            $request->user(),
            $companyId,
        );

        return back();
    }
}
