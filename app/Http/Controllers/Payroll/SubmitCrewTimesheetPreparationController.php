<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\Actions\SubmitCrewTimesheetPreparation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SubmitCrewTimesheetPreparationController extends Controller
{
    public function __invoke(
        Request $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheetPreparation $preparation,
        SubmitCrewTimesheetPreparation $submitCrewTimesheetPreparation,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $submitCrewTimesheetPreparation->handle(
            $payrollPeriod,
            $preparation,
            $request->user(),
            $companyId,
        );

        return redirect()
            ->route('payroll.crew-timeline.show', [$payrollPeriod, $preparation])
            ->with('success', 'Crew timeline submitted for crewing approval.');
    }
}
