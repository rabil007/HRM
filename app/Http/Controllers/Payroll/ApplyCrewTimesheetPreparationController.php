<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApplyCrewTimesheetPreparationController extends Controller
{
    public function __invoke(
        Request $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheetPreparation $preparation,
        ApplyCrewTimesheetPreparation $applyCrewTimesheetPreparation,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $result = $applyCrewTimesheetPreparation->handle(
            $payrollPeriod,
            $preparation,
            $request->user(),
            $companyId,
        );

        $message = $result->idempotent
            ? 'Crew timeline was already applied to timesheets.'
            : "Applied crew timeline to {$result->appliedEmployeeCount} employee timesheet(s).";

        return redirect()
            ->route('payroll.crew-timeline.show', [$payrollPeriod, $preparation])
            ->with('success', $message);
    }
}
