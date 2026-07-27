<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\ClearManualImportCrewTimesheets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClearManualImportCrewTimesheetsController extends Controller
{
    public function __invoke(
        Request $request,
        PayrollPeriod $payrollPeriod,
        ClearManualImportCrewTimesheets $clearManualImportCrewTimesheets,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $result = $clearManualImportCrewTimesheets->handle(
            $payrollPeriod,
            $request->user(),
            $companyId,
        );

        $clearedCount = $result['cleared_count'];

        $message = $clearedCount > 0
            ? "{$clearedCount} Manual/Imported timesheets cleared."
            : 'No Manual or Imported timesheets were found to clear.';

        return back()->with('success', $message);
    }
}
