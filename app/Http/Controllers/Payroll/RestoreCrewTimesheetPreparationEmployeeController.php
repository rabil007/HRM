<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CrewTimesheetPreparation;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\Actions\RestoreCrewTimesheetPreparationEmployee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RestoreCrewTimesheetPreparationEmployeeController extends Controller
{
    public function __invoke(
        Request $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheetPreparation $preparation,
        Employee $employee,
        RestoreCrewTimesheetPreparationEmployee $restoreAction,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $restoreAction->handle(
            $payrollPeriod,
            $preparation,
            $employee,
            $request->user(),
            $companyId,
        );

        return redirect()
            ->route('payroll.crew-timeline.show', [$payrollPeriod, $preparation])
            ->with('success', "Timeline data restored for {$employee->name}.");
    }
}
