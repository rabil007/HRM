<?php

namespace App\Http\Controllers\Organization;

use App\Exports\LeaveBalanceReportExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Reports\LeaveBalanceReportFilterOptions;
use App\Support\Reports\LeaveBalanceReportFilters;
use App\Support\Reports\LeaveBalanceReportPagePermissions;
use App\Support\Reports\LeaveBalanceReportQuery;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class LeaveBalanceReportController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        /** @var User $user */
        $user = $request->user();
        $businessYear = (int) now(CompanyTimezone::forCompanyId($companyId))->year;
        $filters = LeaveBalanceReportFilters::fromRequest($request, $businessYear);
        $query = new LeaveBalanceReportQuery($companyId, $filters, $user);
        $paginator = $query->paginate($this->resolvePerPage($request, default: 25, allowed: [25, 50, 100]));

        return Inertia::render('organization/reports/leave-balances/index', [
            'balances' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'summary' => $query->summary(),
            'filters' => $filters->toArray(),
            'filter_options' => [
                'years' => LeaveBalanceReportFilterOptions::years($user, $companyId, $businessYear),
                'employees' => LeaveBalanceReportFilterOptions::employees($user, $companyId),
                'departments' => LeaveBalanceReportFilterOptions::departments($user, $companyId),
                'leave_types' => LeaveBalanceReportFilterOptions::leaveTypes($user, $companyId),
                'categories' => LeaveBalanceReportFilterOptions::categories(),
                'employee_statuses' => LeaveBalanceReportFilterOptions::employeeStatuses(),
            ],
            'can' => LeaveBalanceReportPagePermissions::for($user),
        ]);
    }

    public function export(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        /** @var User $user */
        $user = $request->user();
        $businessYear = (int) now(CompanyTimezone::forCompanyId($companyId))->year;
        $filters = LeaveBalanceReportFilters::fromRequest($request, $businessYear);
        $query = new LeaveBalanceReportQuery($companyId, $filters, $user);
        $export = LeaveBalanceReportExport::forQuery($query->exportQuery());
        $filename = 'leave-balance-report-'.now()->toDateString();
        $format = strtolower((string) $request->query('format', 'xlsx'));

        if ($format === 'csv') {
            return Excel::download($export, "{$filename}.csv", ExcelWriter::CSV, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return Excel::download($export, "{$filename}.xlsx", ExcelWriter::XLSX);
    }
}
