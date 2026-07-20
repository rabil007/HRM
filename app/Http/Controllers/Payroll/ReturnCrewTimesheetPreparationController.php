<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\ReturnCrewTimesheetPreparationRequest;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\Actions\ReturnCrewTimesheetPreparation;
use Illuminate\Http\RedirectResponse;

class ReturnCrewTimesheetPreparationController extends Controller
{
    public function __invoke(
        ReturnCrewTimesheetPreparationRequest $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheetPreparation $preparation,
        ReturnCrewTimesheetPreparation $returnCrewTimesheetPreparation,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $returnCrewTimesheetPreparation->handle(
            $payrollPeriod,
            $preparation,
            $request->user(),
            $companyId,
            $request->validated('decision_notes'),
        );

        return redirect()
            ->route('payroll.crew-timeline.show', [$payrollPeriod, $preparation])
            ->with('success', 'Crew timeline returned for correction.');
    }
}
