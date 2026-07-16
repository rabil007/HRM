<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Support\Payroll\PayrollOverviewSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PayrollOverviewController extends Controller
{
    public function __invoke(Request $request): InertiaResponse
    {
        abort_unless(
            $request->user()?->can('payroll.overview.view'),
            403,
        );

        $companyId = (int) $request->attributes->get('current_company_id');

        return Inertia::render('payroll/overview', [
            'summary' => PayrollOverviewSummary::forCompany($companyId),
            'can' => [
                'view_periods' => $request->user()?->can('payroll.periods.view') ?? false,
                'view_records' => $request->user()?->can('payroll.records.view') ?? false,
                'create_period' => $request->user()?->can('payroll.periods.create') ?? false,
                'view_crew_timesheets' => $request->user()?->can('payroll.crew_timesheets.view') ?? false,
                'generate_payslips_from_sheet' => $request->user()?->can('payroll.payslips.generate') ?? false,
            ],
        ]);
    }
}
