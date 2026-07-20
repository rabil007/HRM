<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\ApproveCrewTimesheetPreparationRequest;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\Actions\ApproveCrewTimesheetPreparation;
use Illuminate\Http\RedirectResponse;

class ApproveCrewTimesheetPreparationController extends Controller
{
    public function __invoke(
        ApproveCrewTimesheetPreparationRequest $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheetPreparation $preparation,
        ApproveCrewTimesheetPreparation $approveCrewTimesheetPreparation,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $approveCrewTimesheetPreparation->handle(
            $payrollPeriod,
            $preparation,
            $request->user(),
            $companyId,
            $request->validated('decision_notes'),
        );

        return redirect()
            ->route('payroll.crew-timeline.show', [$payrollPeriod, $preparation])
            ->with('success', 'Crew timeline approved.');
    }
}
