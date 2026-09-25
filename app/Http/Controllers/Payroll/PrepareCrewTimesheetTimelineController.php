<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\PrepareCrewTimesheetTimelineRequest;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\PopulateCrewTimesheetsFromAssignments;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class PrepareCrewTimesheetTimelineController extends Controller
{
    public function __invoke(
        PrepareCrewTimesheetTimelineRequest $request,
        PayrollPeriod $payrollPeriod,
        PopulateCrewTimesheetsFromAssignments $populateCrewTimesheetsFromAssignments,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $cutoffDate = $request->filled('cutoff_date')
            ? CarbonImmutable::parse($request->string('cutoff_date')->toString())
            : null;

        $result = $populateCrewTimesheetsFromAssignments->handle(
            $payrollPeriod,
            $request->user(),
            $companyId,
            $cutoffDate,
        );

        $appliedCount = $result['applied_employee_count'];
        $verb = ($result['was_refresh'] ?? false) ? 'refreshed' : 'populated';

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with(
                'success',
                "Crew Timesheets {$verb} from Crew Assignments for {$appliedCount} employee(s).",
            );
    }
}
