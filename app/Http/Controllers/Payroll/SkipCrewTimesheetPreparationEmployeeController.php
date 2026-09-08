<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\SkipCrewTimesheetPreparationEmployeeRequest;
use App\Models\CrewTimesheetPreparation;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\Actions\SkipCrewTimesheetPreparationEmployee;
use Illuminate\Http\RedirectResponse;

class SkipCrewTimesheetPreparationEmployeeController extends Controller
{
    public function __invoke(
        SkipCrewTimesheetPreparationEmployeeRequest $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheetPreparation $preparation,
        Employee $employee,
        SkipCrewTimesheetPreparationEmployee $skipAction,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $skipAction->handle(
            $payrollPeriod,
            $preparation,
            $employee,
            $request->user(),
            $companyId,
            $request->validated('reason'),
        );

        return redirect()
            ->route('payroll.crew-timeline.show', [$payrollPeriod, $preparation])
            ->with('success', "Timeline data skipped for {$employee->name}.");
    }
}
