<?php

namespace App\Http\Controllers\Organization;

use App\Exports\LeaveReportExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Pagination\ResolvesPerPage;
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
        $user = $request->user();
        $filters = LeaveReportFilters::fromRequest($request);
        $timezone = $this->companyTimezone($companyId);
        $query = new LeaveReportQuery($companyId, $filters, $timezone, $user);
        $paginator = $query->paginate($this->resolvePerPage($request, default: 25, allowed: [25, 50, 100]));

        return Inertia::render('organization/reports/leave/index', [
            'leave_requests' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'summary' => $query->summary(),
            'filters' => $filters->toArray(),
            'filter_options' => [
                'statuses' => collect(['pending', 'approved', 'rejected', 'cancelled'])
                    ->map(fn (string $status) => [
                        'value' => $status,
                        'label' => LeaveReportPresenter::statusLabel($status),
                    ])
                    ->all(),
                'employees' => $this->visibleEmployeeOptions($user, $companyId),
                'leave_types' => LeaveType::query()
                    ->where('company_id', $companyId)
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($type) => ['id' => (int) $type->id, 'name' => (string) $type->name])
                    ->values()
                    ->all(),
                'departments' => $this->visibleDepartmentOptions($user, $companyId),
                'branches' => Branch::query()
                    ->where('company_id', $companyId)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($branch) => ['id' => (int) $branch->id, 'name' => (string) $branch->name])
                    ->values()
                    ->all(),
            ],
            'can' => LeaveReportPagePermissions::for($user),
        ]);
    }

    public function export(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = LeaveReportFilters::fromRequest($request);
        $timezone = $this->companyTimezone($companyId);
        $query = new LeaveReportQuery($companyId, $filters, $timezone, $request->user());
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

    /**
     * @return list<array{id: int, name: string, employee_no: string|null}>
     */
    private function visibleEmployeeOptions(?User $user, int $companyId): array
    {
        $query = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name');

        EmployeeVisibilityScope::apply($query, $user, $companyId);

        return $query
            ->get(['id', 'employee_no', 'name'])
            ->map(fn (Employee $employee) => [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function visibleDepartmentOptions(?User $user, int $companyId): array
    {
        $query = Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name');

        if ($user !== null) {
            $allowedIds = EmployeeVisibilityScope::allowedDepartmentIds($user, $companyId);

            if ($allowedIds === []) {
                $query->whereRaw('1 = 0');
            } elseif ($allowedIds !== null) {
                $query->whereIn('id', $allowedIds);
            }
        }

        return $query
            ->get(['id', 'name'])
            ->map(fn (Department $department) => ['id' => (int) $department->id, 'name' => (string) $department->name])
            ->values()
            ->all();
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('timezone') ?? config('app.timezone', 'UTC'));
    }
}
