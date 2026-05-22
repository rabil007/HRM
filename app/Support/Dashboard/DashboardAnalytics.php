<?php

namespace App\Support\Dashboard;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use Carbon\Carbon;

final class DashboardAnalytics
{
    public function __construct(
        private DocumentBrowseQuery $documentBrowse,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forCompany(int $companyId): array
    {
        $documentSummary = $this->documentBrowse->expirySummary($companyId);

        $uploadedThisMonth = (int) EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $employeeStats = Employee::query()
            ->where('company_id', $companyId)
            ->selectRaw('COUNT(*) as `total`')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as `active`")
            ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as `inactive`")
            ->selectRaw("SUM(CASE WHEN status = 'on_leave' THEN 1 ELSE 0 END) as `on_leave`")
            ->selectRaw("SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) as `terminated`")
            ->selectRaw('SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as `with_user`')
            ->first();

        $newHiresThisMonth = (int) Employee::query()
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $totalEmployees = (int) ($employeeStats->total ?? 0);
        $totalDocuments = $documentSummary['total_documents'];
        $expired = $documentSummary['expired'];
        $expiring30 = $documentSummary['expiring_30'];

        $complianceRate = $totalDocuments > 0
            ? (int) round((($totalDocuments - $expired) / $totalDocuments) * 100)
            : 100;

        return [
            'employee_analytics' => [
                'total' => $totalEmployees,
                'active' => (int) ($employeeStats->active ?? 0),
                'inactive' => (int) ($employeeStats->inactive ?? 0),
                'on_leave' => (int) ($employeeStats->on_leave ?? 0),
                'terminated' => (int) ($employeeStats->terminated ?? 0),
                'new_hires_this_month' => $newHiresThisMonth,
                'with_user_account' => (int) ($employeeStats->with_user ?? 0),
                'without_user_account' => max(0, $totalEmployees - (int) ($employeeStats->with_user ?? 0)),
            ],
            'document_compliance' => [
                'total_documents' => $totalDocuments,
                'expired' => $expired,
                'expiring_30' => $expiring30,
                'expiring_15' => $documentSummary['expiring_15'],
                'expiring_7' => $documentSummary['expiring_7'],
                'uploaded_this_month' => $uploadedThisMonth,
                'compliance_rate' => $complianceRate,
                'avg_per_employee' => $totalEmployees > 0
                    ? round($totalDocuments / $totalEmployees, 1)
                    : 0,
            ],
            'workforce_trends' => $this->workforceTrends($companyId),
            'employees_by_department' => $this->employeesByDepartment($companyId),
            'employees_by_branch' => $this->employeesByBranch($companyId),
            'document_health' => $this->documentHealth($documentSummary),
            'organization_snapshot' => [
                'departments' => (int) Department::query()->where('company_id', $companyId)->count(),
                'branches' => (int) Branch::query()->where('company_id', $companyId)->count(),
            ],
            'recent_hires' => $this->recentHires($companyId),
        ];
    }

    /**
     * @return list<array{month: string, headcount: int, new_hires: int, documents: int}>
     */
    private function workforceTrends(int $companyId): array
    {
        $points = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $newHires = (int) Employee::query()
                ->where('company_id', $companyId)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $documents = (int) EmployeeDocument::query()
                ->where('company_id', $companyId)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $headcount = (int) Employee::query()
                ->where('company_id', $companyId)
                ->where('created_at', '<=', $end)
                ->count();

            $points[] = [
                'month' => $month->format('M'),
                'headcount' => $headcount,
                'new_hires' => $newHires,
                'documents' => $documents,
            ];
        }

        return $points;
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    private function employeesByDepartment(int $companyId): array
    {
        return Employee::query()
            ->where('employees.company_id', $companyId)
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->selectRaw("COALESCE(departments.name, 'Unassigned') as label")
            ->selectRaw('COUNT(*) as count')
            ->groupByRaw("COALESCE(departments.name, 'Unassigned')")
            ->orderByDesc('count')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->label,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    private function employeesByBranch(int $companyId): array
    {
        return Employee::query()
            ->where('employees.company_id', $companyId)
            ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
            ->selectRaw("COALESCE(branches.name, 'Unassigned') as label")
            ->selectRaw('COUNT(*) as count')
            ->groupByRaw("COALESCE(branches.name, 'Unassigned')")
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->label,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * @param  array{total_documents: int, expired: int, expiring_30: int, expiring_15: int, expiring_7: int}  $summary
     * @return list<array{name: string, value: int, key: string}>
     */
    private function documentHealth(array $summary): array
    {
        $total = $summary['total_documents'];
        $expired = $summary['expired'];
        $expiring7 = $summary['expiring_7'];
        $expiring30 = $summary['expiring_30'];
        $expiring8To30 = max(0, $expiring30 - $expiring7);
        $compliant = max(0, $total - $expired - $expiring30);

        return collect([
            ['name' => 'Compliant', 'value' => $compliant, 'key' => 'compliant'],
            ['name' => 'Due in 8–30 days', 'value' => $expiring8To30, 'key' => 'expiring_30'],
            ['name' => 'Due in 7 days', 'value' => $expiring7, 'key' => 'expiring_7'],
            ['name' => 'Expired', 'value' => $expired, 'key' => 'expired'],
        ])
            ->filter(fn (array $row) => $row['value'] > 0)
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, employee_no: string, hired_at: string}>
     */
    private function recentHires(int $companyId): array
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'name', 'employee_no', 'created_at'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'hired_at' => Carbon::parse($employee->created_at)->format('d M Y'),
            ])
            ->all();
    }
}
