<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\ApproveCrewTimesheetApprovalRequest;
use App\Http\Requests\Organization\Payroll\ReturnCrewTimesheetApprovalRequest;
use App\Http\Requests\Organization\Payroll\SubmitCrewTimesheetApprovalRequest;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\ApproveCrewTimesheetApproval;
use App\Support\Payroll\Actions\ReturnCrewTimesheetApproval;
use App\Support\Payroll\Actions\SubmitCrewTimesheetApproval;
use Illuminate\Http\RedirectResponse;

class CrewTimesheetApprovalController extends Controller
{
    public function submit(
        SubmitCrewTimesheetApprovalRequest $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheet $timesheet,
        SubmitCrewTimesheetApproval $submit,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $submit->handle($payrollPeriod, $timesheet, $request->user(), $companyId);

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', 'Timesheet submitted for approval.');
    }

    public function approve(
        ApproveCrewTimesheetApprovalRequest $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheet $timesheet,
        ApproveCrewTimesheetApproval $approve,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $approve->handle($payrollPeriod, $timesheet, $request->user(), $companyId);

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', 'Timesheet approved.');
    }

    public function return(
        ReturnCrewTimesheetApprovalRequest $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheet $timesheet,
        ReturnCrewTimesheetApproval $return,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $return->handle(
            $payrollPeriod,
            $timesheet,
            $request->user(),
            $companyId,
            (string) $request->validated('return_reason'),
        );

        return redirect()
            ->route('payroll.show', $payrollPeriod)
            ->with('success', 'Timesheet returned.');
    }
}
