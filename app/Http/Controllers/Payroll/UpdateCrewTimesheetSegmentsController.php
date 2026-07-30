<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\UpdateCrewTimesheetSegmentsRequest;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\ReplaceCrewTimesheetSegments;
use Illuminate\Http\RedirectResponse;

class UpdateCrewTimesheetSegmentsController extends Controller
{
    public function __invoke(
        UpdateCrewTimesheetSegmentsRequest $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheet $timesheet,
        ReplaceCrewTimesheetSegments $replaceCrewTimesheetSegments,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        abort_unless($payrollPeriod->isCrew(), 404);
        abort_unless((int) $timesheet->company_id === $companyId, 404);
        abort_unless((int) $timesheet->period_id === (int) $payrollPeriod->id, 404);

        $replaceCrewTimesheetSegments->handle(
            $payrollPeriod,
            $timesheet,
            $request->segments(),
            $request->user(),
            $companyId,
        );

        return back();
    }
}
