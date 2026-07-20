<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\PrepareCrewTimesheetTimelineRequest;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class PrepareCrewTimesheetTimelineController extends Controller
{
    public function __invoke(
        PrepareCrewTimesheetTimelineRequest $request,
        PayrollPeriod $payrollPeriod,
        PrepareCrewTimesheetTimeline $prepareCrewTimesheetTimeline,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $cutoffDate = $request->filled('cutoff_date')
            ? CarbonImmutable::parse($request->string('cutoff_date')->toString())
            : null;

        $preparation = $prepareCrewTimesheetTimeline->handle(
            $payrollPeriod,
            $companyId,
            (int) $request->user()->id,
            $cutoffDate,
        );

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with(
                'success',
                "Crew Operations timeline prepared as draft version {$preparation->version}.",
            );
    }
}
