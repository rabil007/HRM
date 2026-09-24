<?php

namespace App\Http\Controllers\Organization;

use App\Exports\LeaveReportExport;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Reports\LeaveReportDepartmentTree;
use App\Support\Reports\LeaveReportFilterOptions;
use App\Support\Reports\LeaveReportFilters;
use App\Support\Reports\LeaveReportPagePermissions;
use App\Support\Reports\LeaveReportPresenter;
use App\Support\Reports\LeaveReportQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class LeaveReportController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        /** @var User $user */
        $user = $request->user();
        $filters = LeaveReportFilters::fromRequest($request);
        $timezone = $this->companyTimezone($companyId);
        $query = new LeaveReportQuery($companyId, $filters, $timezone, $user);
        $paginator = $query->paginate($this->resolvePerPage($request, default: 25, allowed: [25, 50, 100]));
        $leaveTypes = LeaveReportFilterOptions::leaveTypes($user, $companyId);
        $leaveTypeCounts = $query->leaveTypeRequestCounts();

        return Inertia::render('organization/reports/leave/index', [
            'leave_requests' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'summary' => [
                ...$query->summary(),
                'total_requests' => (int) array_sum($leaveTypeCounts),
                'leave_types' => array_map(
                    fn (array $type): array => [
                        ...$type,
                        'request_count' => (int) ($leaveTypeCounts[$type['id']] ?? 0),
                    ],
                    $leaveTypes,
                ),
            ],
            'filters' => $filters->toArray(),
            'filter_options' => [
                'statuses' => collect(['pending', 'approved', 'rejected', 'cancelled'])
                    ->map(fn (string $status) => [
                        'value' => $status,
                        'label' => LeaveReportPresenter::statusLabel($status),
                    ])
                    ->all(),
                'employees' => LeaveReportFilterOptions::employees($user, $companyId),
                'leave_types' => $leaveTypes,
                'departments' => LeaveReportFilterOptions::departments($user, $companyId),
            ],
            'department_tree' => LeaveReportDepartmentTree::for(
                $companyId,
                new EmployeeDirectoryFilters(departmentId: $filters->departmentId),
                $user,
            ),
            'department_tree_selected_id' => $filters->departmentId !== '' ? (int) $filters->departmentId : null,
            'can' => LeaveReportPagePermissions::for($user),
        ]);
    }

    public function export(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        /** @var User $user */
        $user = $request->user();
        $filters = LeaveReportFilters::fromRequest($request);
        $timezone = $this->companyTimezone($companyId);
        $query = new LeaveReportQuery($companyId, $filters, $timezone, $user);
        $export = LeaveReportExport::forQuery($query->exportQuery(), $timezone);
        $filename = 'leave-report-'.now()->toDateString();
        $format = strtolower((string) $request->query('format', 'xlsx'));

        if ($format === 'csv') {
            return Excel::download($export, "{$filename}.csv", ExcelWriter::CSV, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return Excel::download($export, "{$filename}.xlsx", ExcelWriter::XLSX);
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('timezone') ?? config('app.timezone', 'UTC'));
    }
}
