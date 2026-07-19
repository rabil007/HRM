<?php

namespace App\Support\Dashboard;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

final class DashboardAnalytics
{
    public const CACHE_TTL_SECONDS = 45;

    public const CACHE_KEY_PREFIX = 'dashboard.analytics.company.';

    public static bool $forceCacheInTests = false;

    public function __construct(
        private DocumentBrowseQuery $documentBrowse,
    ) {}

    public static function cacheKey(int $companyId, string $part = 'primary'): string
    {
        return self::CACHE_KEY_PREFIX.$companyId.'.'.$part;
    }

    public static function forgetCompany(int $companyId): void
    {
        foreach (['primary', 'workforce_trends', 'employees_by_department', 'employees_by_branch', 'recent_hires'] as $part) {
            Cache::forget(self::cacheKey($companyId, $part));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function forCompany(int $companyId): array
    {
        return array_merge(
            $this->primaryForCompany($companyId),
            [
                'workforce_trends' => $this->workforceTrends($companyId),
                'employees_by_department' => $this->employeesByDepartment($companyId),
                'employees_by_branch' => $this->employeesByBranch($companyId),
                'recent_hires' => $this->recentHires($companyId),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function primaryForCompany(int $companyId): array
    {
        return $this->remember($companyId, 'primary', function () use ($companyId): array {
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
                ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as `new_hires_this_month`', [
                    now()->startOfMonth()->toDateTimeString(),
                    now()->endOfMonth()->toDateTimeString(),
                ])
                ->first();

            $totalEmployees = (int) ($employeeStats->total ?? 0);
            $totalDocuments = $documentSummary['total_documents'];
            $expired = $documentSummary['expired'];
            $activeEmployees = (int) ($employeeStats->active ?? 0);

            $complianceRate = $totalDocuments > 0
                ? (int) round((($totalDocuments - $expired) / $totalDocuments) * 100)
                : 100;

            return [
                'employee_analytics' => [
                    'total' => $totalEmployees,
                    'active' => $activeEmployees,
                    'inactive' => (int) ($employeeStats->inactive ?? 0),
                    'on_leave' => (int) ($employeeStats->on_leave ?? 0),
                    'terminated' => (int) ($employeeStats->terminated ?? 0),
                    'new_hires_this_month' => (int) ($employeeStats->new_hires_this_month ?? 0),
                    'with_user_account' => (int) ($employeeStats->with_user ?? 0),
                    'without_user_account' => max(0, $totalEmployees - (int) ($employeeStats->with_user ?? 0)),
                ],
                'document_compliance' => [
                    'total_documents' => $totalDocuments,
                    'expired' => $expired,
                    'expiring_30' => $documentSummary['expiring_30'],
                    'expiring_15' => $documentSummary['expiring_15'],
                    'expiring_7' => $documentSummary['expiring_7'],
                    'uploaded_this_month' => $uploadedThisMonth,
                    'compliance_rate' => $complianceRate,
                    'avg_per_employee' => $totalEmployees > 0
                        ? round($totalDocuments / $totalEmployees, 1)
                        : 0,
                ],
                'document_health' => $this->documentHealth($documentSummary),
                'organization_snapshot' => $this->organizationSnapshot($companyId),
                'attendance_analytics' => $this->attendanceAnalytics($companyId, $activeEmployees),
            ];
        });
    }

    /**
     * @return list<array{month: string, headcount: int, new_hires: int, documents: int}>
     */
    public function workforceTrends(int $companyId): array
    {
        return $this->remember($companyId, 'workforce_trends', function () use ($companyId): array {
            // Headcount uses employee created_at (system record creation), not hire_date.
            $months = [];
            $rangeStart = now()->subMonths(5)->startOfMonth();
            $rangeEnd = now()->endOfMonth();

            for ($i = 5; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $months[$month->format('Y-m')] = [
                    'month' => $month->format('M'),
                    'new_hires' => 0,
                    'documents' => 0,
                ];
            }

            $baselineHeadcount = (int) Employee::query()
                ->where('company_id', $companyId)
                ->where('created_at', '<', $rangeStart)
                ->count();

            $hireDates = Employee::query()
                ->where('company_id', $companyId)
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->pluck('created_at');

            foreach ($hireDates as $createdAt) {
                $key = Carbon::parse($createdAt)->format('Y-m');

                if (isset($months[$key])) {
                    $months[$key]['new_hires']++;
                }
            }

            $documentDates = EmployeeDocument::query()
                ->where('company_id', $companyId)
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->pluck('created_at');

            foreach ($documentDates as $createdAt) {
                $key = Carbon::parse($createdAt)->format('Y-m');

                if (isset($months[$key])) {
                    $months[$key]['documents']++;
                }
            }

            $points = [];
            $runningHeadcount = $baselineHeadcount;

            foreach ($months as $meta) {
                $runningHeadcount += $meta['new_hires'];

                $points[] = [
                    'month' => $meta['month'],
                    'headcount' => $runningHeadcount,
                    'new_hires' => $meta['new_hires'],
                    'documents' => $meta['documents'],
                ];
            }

            return $points;
        });
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    public function employeesByDepartment(int $companyId): array
    {
        return $this->remember($companyId, 'employees_by_department', function () use ($companyId): array {
            return Employee::query()
                ->where('employees.company_id', $companyId)
                ->active()
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
        });
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    public function employeesByBranch(int $companyId): array
    {
        return $this->remember($companyId, 'employees_by_branch', function () use ($companyId): array {
            return Employee::query()
                ->where('employees.company_id', $companyId)
                ->active()
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
        });
    }

    /**
     * @return list<array{id: int, name: string, employee_no: string, hired_at: string}>
     */
    public function recentHires(int $companyId): array
    {
        return $this->remember($companyId, 'recent_hires', function () use ($companyId): array {
            return Employee::query()
                ->where('company_id', $companyId)
                ->active()
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
        });
    }

    /**
     * @return array{departments: int, branches: int}
     */
    private function organizationSnapshot(int $companyId): array
    {
        return [
            'departments' => (int) Department::query()->where('company_id', $companyId)->count(),
            'branches' => (int) Branch::query()->where('company_id', $companyId)->count(),
        ];
    }

    /**
     * @return array{
     *     check_ins_today: int,
     *     check_outs_today: int,
     *     events_today: int,
     *     present_today: int,
     *     late_today: int,
     *     absent_today: int,
     *     active_employees: int,
     *     weekly_trends: list<array{day: string, check_ins: int, check_outs: int}>,
     *     recent_records: list<array{
     *         id: int,
     *         date: string|null,
     *         clock_in: string|null,
     *         clock_out: string|null,
     *         employee_name: string|null,
     *         employee_id: int|null,
     *         status: string,
     *         source: string|null
     *     }>
     * }
     */
    private function attendanceAnalytics(int $companyId, int $activeEmployees): array
    {
        $timezone = Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone');

        $todayDate = now($timezone)->toDateString();
        $weekStart = now($timezone)->subDays(6)->toDateString();

        $todayRow = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereDate('date', $todayDate)
            ->selectRaw(
                'COUNT(*) as events_today,
                SUM(CASE WHEN clock_in IS NOT NULL THEN 1 ELSE 0 END) as check_ins_today,
                SUM(CASE WHEN clock_out IS NOT NULL THEN 1 ELSE 0 END) as check_outs_today,
                SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as present_today,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as late_today,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as absent_today',
                [
                    AttendanceRecord::STATUS_PRESENT,
                    AttendanceRecord::STATUS_LATE,
                    AttendanceRecord::STATUS_HALF_DAY,
                    AttendanceRecord::STATUS_LATE,
                    AttendanceRecord::STATUS_ABSENT,
                ],
            )
            ->first();

        return [
            'check_ins_today' => (int) ($todayRow->check_ins_today ?? 0),
            'check_outs_today' => (int) ($todayRow->check_outs_today ?? 0),
            'events_today' => (int) ($todayRow->events_today ?? 0),
            'present_today' => (int) ($todayRow->present_today ?? 0),
            'late_today' => (int) ($todayRow->late_today ?? 0),
            'absent_today' => (int) ($todayRow->absent_today ?? 0),
            'active_employees' => $activeEmployees,
            'weekly_trends' => $this->attendanceWeeklyTrends($companyId, $timezone, $weekStart, $todayDate),
            'recent_records' => $this->recentAttendanceRecords($companyId),
        ];
    }

    /**
     * @return list<array{day: string, check_ins: int, check_outs: int}>
     */
    private function attendanceWeeklyTrends(
        int $companyId,
        string $timezone,
        string $weekStart,
        string $weekEnd,
    ): array {
        $rows = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereDate('date', '>=', $weekStart)
            ->whereDate('date', '<=', $weekEnd)
            ->selectRaw('date as attendance_date')
            ->selectRaw('SUM(CASE WHEN clock_in IS NOT NULL THEN 1 ELSE 0 END) as check_ins')
            ->selectRaw('SUM(CASE WHEN clock_out IS NOT NULL THEN 1 ELSE 0 END) as check_outs')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row): string => Carbon::parse($row->attendance_date)->timezone($timezone)->toDateString());

        $points = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now($timezone)->subDays($i)->startOfDay();
            $key = $date->toDateString();
            $row = $rows->get($key);

            $points[] = [
                'day' => $date->format('D'),
                'check_ins' => (int) ($row->check_ins ?? 0),
                'check_outs' => (int) ($row->check_outs ?? 0),
            ];
        }

        return $points;
    }

    /**
     * @return list<array{
     *     id: int,
     *     date: string|null,
     *     clock_in: string|null,
     *     clock_out: string|null,
     *     employee_name: string|null,
     *     employee_id: int|null,
     *     status: string,
     *     source: string|null
     * }>
     */
    private function recentAttendanceRecords(int $companyId): array
    {
        return AttendanceRecord::query()
            ->with('employee:id,name')
            ->where('company_id', $companyId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (AttendanceRecord $record): array => [
                'id' => $record->id,
                'date' => $record->date?->toDateString(),
                'clock_in' => $record->clock_in?->toIso8601String(),
                'clock_out' => $record->clock_out?->toIso8601String(),
                'employee_name' => $record->employee?->name,
                'employee_id' => $record->employee?->id,
                'status' => $record->status,
                'source' => $record->source,
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
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function remember(int $companyId, string $part, callable $callback): mixed
    {
        if (app()->runningUnitTests() && ! self::$forceCacheInTests) {
            return $callback();
        }

        try {
            return Cache::remember(
                self::cacheKey($companyId, $part),
                self::CACHE_TTL_SECONDS,
                $callback,
            );
        } catch (\Throwable) {
            return $callback();
        }
    }
}
