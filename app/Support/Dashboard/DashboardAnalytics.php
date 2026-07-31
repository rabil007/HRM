<?php

namespace App\Support\Dashboard;

use App\Enums\AnnouncementStatus;
use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Activity\ActivityChangePresenter;
use App\Support\Attendance\LeaveRequestVisibility;
use App\Support\Attendance\LeaveTypeYearBalance;
use App\Support\BankAccounts\BankAccountSummaryQuery;
use App\Support\Contracts\ContractDirectoryFilters;
use App\Support\Contracts\ContractSummaryQuery;
use App\Support\CrewOperations\CrewOperationsDashboardAnalytics;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use App\Support\EmployeeTrainings\TrainingSummaryQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

final class DashboardAnalytics
{
    public const CACHE_TTL_SECONDS = 45;

    public const CACHE_KEY_PREFIX = 'dashboard.company.';

    public static bool $forceCacheInTests = false;

    public function __construct(
        private DocumentBrowseQuery $documentBrowse,
        private ContractSummaryQuery $contractSummaryQuery,
        private TrainingSummaryQuery $trainingSummaryQuery,
        private BankAccountSummaryQuery $bankAccountSummaryQuery,
        private LeaveRequestVisibility $leaveRequestVisibility,
    ) {}

    public static function cacheKey(int $companyId, string $part = 'primary'): string
    {
        return self::CACHE_KEY_PREFIX.$companyId.'.'.$part;
    }

    public static function userCacheKey(int $companyId, int $userId, string $part): string
    {
        return self::CACHE_KEY_PREFIX.$companyId.'.user.'.$userId.'.'.$part;
    }

    public static function forgetCompany(int $companyId): void
    {
        $parts = [
            'primary',
            'workforce',
            'organization',
            'documents',
            'attendance',
            'contracts',
            'training',
            'bank_accounts',
            'payroll',
            'crew',
            'announcements',
            'audit',
            'workforce_trends',
            'employees_by_department',
            'employees_by_branch',
            'recent_hires',
        ];

        foreach ($parts as $part) {
            Cache::forget(self::cacheKey($companyId, $part));
        }
    }

    /**
     * Backward-compatible combined method for full company dashboard analytics.
     *
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
     * Backward-compatible method combining workforce, organization, documents, and attendance.
     *
     * @return array<string, mixed>
     */
    public function primaryForCompany(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'primary', function () use ($companyId): array {
            $workforce = $this->workforceSummary($companyId);
            $org = $this->organizationSummary($companyId);
            $docs = $this->documentSummary($companyId);
            $attendance = $this->attendanceSummary($companyId);

            return [
                'employee_analytics' => $workforce,
                'organization_snapshot' => $org,
                'document_compliance' => $docs['document_compliance'],
                'document_health' => $docs['document_health'],
                'attendance_analytics' => $attendance,
            ];
        });
    }

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     inactive: int,
     *     on_leave: int,
     *     terminated: int,
     *     new_hires_this_month: int,
     *     records_added_this_month: int,
     *     with_user_account: int,
     *     without_user_account: int
     * }
     */
    public function workforceSummary(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'workforce', function () use ($companyId): array {
            $timezone = CompanyTimezone::forCompanyId($companyId);
            $startOfMonth = now($timezone)->startOfMonth()->toDateString();
            $endOfMonth = now($timezone)->endOfMonth()->toDateString();

            $employeeStats = Employee::query()
                ->where('company_id', $companyId)
                ->selectRaw('COUNT(*) as `total`')
                ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as `active`")
                ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as `inactive`")
                ->selectRaw("SUM(CASE WHEN status = 'on_leave' THEN 1 ELSE 0 END) as `on_leave`")
                ->selectRaw("SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) as `terminated`")
                ->selectRaw('SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as `with_user`')
                ->selectRaw(
                    'SUM(CASE WHEN COALESCE(hire_date, created_at) >= ? AND COALESCE(hire_date, created_at) <= ? THEN 1 ELSE 0 END) as `new_hires_this_month`',
                    [$startOfMonth, $endOfMonth]
                )
                ->selectRaw(
                    'SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END) as `records_added_this_month`',
                    [now($timezone)->startOfMonth()->toDateTimeString(), now($timezone)->endOfMonth()->toDateTimeString()]
                )
                ->first();

            $totalEmployees = (int) ($employeeStats->total ?? 0);
            $withUser = (int) ($employeeStats->with_user ?? 0);

            return [
                'total' => $totalEmployees,
                'active' => (int) ($employeeStats->active ?? 0),
                'inactive' => (int) ($employeeStats->inactive ?? 0),
                'on_leave' => (int) ($employeeStats->on_leave ?? 0),
                'terminated' => (int) ($employeeStats->terminated ?? 0),
                'new_hires_this_month' => (int) ($employeeStats->new_hires_this_month ?? 0),
                'records_added_this_month' => (int) ($employeeStats->records_added_this_month ?? 0),
                'with_user_account' => $withUser,
                'without_user_account' => max(0, $totalEmployees - $withUser),
            ];
        });
    }

    /**
     * @return array{departments: int, branches: int}
     */
    public function organizationSummary(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'organization', function () use ($companyId): array {
            return [
                'departments' => (int) Department::query()->where('company_id', $companyId)->count(),
                'branches' => (int) Branch::query()->where('company_id', $companyId)->count(),
            ];
        });
    }

    /**
     * @return array{
     *     document_compliance: array{
     *         total_documents: int,
     *         expired: int,
     *         expiring_30: int,
     *         expiring_15: int,
     *         expiring_7: int,
     *         uploaded_this_month: int,
     *         compliance_rate: int,
     *         uploaded_document_validity: int,
     *         avg_per_employee: float
     *     },
     *     document_health: list<array{name: string, value: int, key: string}>
     * }
     */
    public function documentSummary(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'documents', function () use ($companyId): array {
            $documentSummary = $this->documentBrowse->expirySummary($companyId);

            $timezone = CompanyTimezone::forCompanyId($companyId);
            $uploadedThisMonth = (int) EmployeeDocument::query()
                ->where('company_id', $companyId)
                ->whereBetween('created_at', [now($timezone)->startOfMonth()->toDateTimeString(), now($timezone)->endOfMonth()->toDateTimeString()])
                ->count();

            $totalEmployees = (int) Employee::query()->where('company_id', $companyId)->count();
            $totalDocuments = $documentSummary['total_documents'];
            $expired = $documentSummary['expired'];

            $validityRate = $totalDocuments > 0
                ? (int) round((($totalDocuments - $expired) / $totalDocuments) * 100)
                : 100;

            return [
                'document_compliance' => [
                    'total_documents' => $totalDocuments,
                    'expired' => $expired,
                    'expiring_30' => $documentSummary['expiring_30'],
                    'expiring_15' => $documentSummary['expiring_15'],
                    'expiring_7' => $documentSummary['expiring_7'],
                    'uploaded_this_month' => $uploadedThisMonth,
                    'compliance_rate' => $validityRate,
                    'uploaded_document_validity' => $validityRate,
                    'avg_per_employee' => $totalEmployees > 0
                        ? round($totalDocuments / $totalEmployees, 1)
                        : 0.0,
                ],
                'document_health' => $this->documentHealth($documentSummary),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function attendanceSummary(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'attendance', function () use ($companyId): array {
            $activeEmployees = (int) Employee::query()->where('company_id', $companyId)->active()->count();
            $timezone = CompanyTimezone::forCompanyId($companyId);
            $todayDate = now($timezone)->toDateString();
            $tomorrowDate = now($timezone)->addDay()->toDateString();
            $weekStart = now($timezone)->subDays(6)->toDateString();

            $distinctRow = AttendanceRecord::query()
                ->where('company_id', $companyId)
                ->where('date', '>=', $todayDate)
                ->where('date', '<', $tomorrowDate)
                ->selectRaw('
                    COUNT(DISTINCT CASE WHEN clock_in IS NOT NULL THEN employee_id END) as check_ins_today,
                    COUNT(DISTINCT CASE WHEN clock_out IS NOT NULL THEN employee_id END) as check_outs_today,
                    COUNT(DISTINCT CASE WHEN status IN (?, ?, ?) THEN employee_id END) as present_today,
                    COUNT(DISTINCT CASE WHEN status = ? THEN employee_id END) as late_today,
                    COUNT(DISTINCT CASE WHEN status = ? THEN employee_id END) as absent_today,
                    COUNT(*) as events_today',
                    [
                        AttendanceRecord::STATUS_PRESENT,
                        AttendanceRecord::STATUS_LATE,
                        AttendanceRecord::STATUS_HALF_DAY,
                        AttendanceRecord::STATUS_LATE,
                        AttendanceRecord::STATUS_ABSENT,
                    ],
                )
                ->first();

            $presentToday = (int) ($distinctRow->present_today ?? 0);
            $checkInsToday = (int) ($distinctRow->check_ins_today ?? 0);
            $checkOutsToday = (int) ($distinctRow->check_outs_today ?? 0);
            $lateToday = (int) ($distinctRow->late_today ?? 0);
            $absentToday = (int) ($distinctRow->absent_today ?? 0);
            $eventsToday = (int) ($distinctRow->events_today ?? 0);

            return [
                'check_ins_today' => $checkInsToday,
                'check_outs_today' => $checkOutsToday,
                'events_today' => $eventsToday,
                'attendance_events_today' => $eventsToday,
                'present_today' => $presentToday,
                'unique_employees_present' => $presentToday,
                'late_today' => $lateToday,
                'absent_today' => $absentToday,
                'active_employees' => $activeEmployees,
                'weekly_trends' => $this->attendanceWeeklyTrends($companyId, $timezone, $weekStart, $todayDate),
                'recent_records' => $this->recentAttendanceRecords($companyId),
            ];
        });
    }

    /**
     * @return array{
     *     on_leave_today: int,
     *     upcoming_this_week: int,
     *     pending_requests: int,
     *     awaiting_my_approval: int,
     *     oldest_pending_date: string|null
     * }
     */
    public function leaveSummary(int $companyId, User $user): array
    {
        return $this->rememberUser($companyId, $user->id, 'leave', function () use ($companyId, $user): array {
            $timezone = CompanyTimezone::forCompanyId($companyId);
            $today = now($timezone)->toDateString();
            $in7Days = now($timezone)->addDays(7)->toDateString();

            $onLeaveToday = (int) LeaveRequest::query()
                ->where('company_id', $companyId)
                ->where('status', 'approved')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->count();

            $upcomingThisWeek = (int) LeaveRequest::query()
                ->where('company_id', $companyId)
                ->where('status', 'approved')
                ->where('start_date', '>', $today)
                ->where('start_date', '<=', $in7Days)
                ->count();

            $pendingQuery = LeaveRequest::query()->where('company_id', $companyId)->where('status', 'pending');
            $this->leaveRequestVisibility->applyIndexScope($pendingQuery, $user, $companyId);
            $pendingCount = $pendingQuery->count();

            $awaitingApprovalCount = 0;
            $oldestPendingDate = null;

            if ($user->can('attendance.leave-requests.approve')) {
                $awaitingQuery = LeaveRequest::query();
                $this->leaveRequestVisibility->applyAwaitingMyApprovalScope($awaitingQuery, $user, $companyId);
                $awaitingApprovalCount = $awaitingQuery->count();

                $oldestRequest = (clone $awaitingQuery)->orderBy('created_at')->first();
                $oldestPendingDate = $oldestRequest?->created_at?->toDateString();
            }

            return [
                'on_leave_today' => $onLeaveToday,
                'upcoming_this_week' => $upcomingThisWeek,
                'pending_requests' => $pendingCount,
                'awaiting_my_approval' => $awaitingApprovalCount,
                'oldest_pending_date' => $oldestPendingDate,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function contractsSummary(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'contracts', function () use ($companyId): array {
            return $this->contractSummaryQuery->forCompany($companyId, new ContractDirectoryFilters);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function trainingSummary(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'training', function () use ($companyId): array {
            return $this->trainingSummaryQuery->forCompany($companyId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function bankAccountsSummary(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'bank_accounts', function () use ($companyId): array {
            return $this->bankAccountSummaryQuery->forCompany($companyId);
        });
    }

    /**
     * Lightweight dashboard-specific payroll summary.
     *
     * @return array{
     *     draft_periods: int,
     *     processing_periods: int,
     *     awaiting_approval_periods: int,
     *     last_paid_period_name: string|null,
     *     last_paid_period_total: float|null
     * }
     */
    public function payrollSummary(int $companyId, User $user): array
    {
        return $this->rememberCompany($companyId, 'payroll', function () use ($companyId, $user): array {
            $draftPeriods = (int) PayrollPeriod::query()
                ->where('company_id', $companyId)
                ->where('status', 'draft')
                ->count();

            $processingPeriods = (int) PayrollPeriod::query()
                ->where('company_id', $companyId)
                ->where('status', 'processing')
                ->count();

            $lastPaid = PayrollPeriod::query()
                ->where('company_id', $companyId)
                ->where('status', 'paid')
                ->orderByDesc('end_date')
                ->first();

            $lastPaidTotal = null;
            if ($lastPaid !== null && $user->can('payroll.overview.view')) {
                $lastPaidTotal = (float) PayrollRecord::query()
                    ->where('company_id', $companyId)
                    ->where('period_id', $lastPaid->id)
                    ->sum('net_salary');
            }

            return [
                'draft_periods' => $draftPeriods,
                'processing_periods' => $processingPeriods,
                'awaiting_approval_periods' => $processingPeriods,
                'last_paid_period_name' => $lastPaid?->name,
                'last_paid_period_total' => $lastPaidTotal,
            ];
        });
    }

    /**
     * Lightweight dashboard-specific crew summary.
     *
     * @return array{
     *     on_vessel: int,
     *     ready_to_join: int,
     *     in_home: int,
     *     at_home: int,
     *     needs_update: int,
     *     movement_updates_required: int,
     *     planned_signoffs_due: int,
     *     overdue_at_home: int,
     *     total: int
     * }
     */
    public function crewSummary(int $companyId, User $user): array
    {
        return $this->rememberCompany($companyId, 'crew', function () use ($companyId, $user): array {
            $crewAnalytics = app(CrewOperationsDashboardAnalytics::class);
            $full = $crewAnalytics->forCompany($companyId, $user);
            $deployment = $full['deployment_summary'] ?? [];

            $needsUpdate = (int) ($full['alert_counts']['needs_update'] ?? 0);
            $dueSoon = (int) ($full['alert_counts']['due_soon'] ?? 0);
            $overdueHome = (int) ($full['alert_counts']['overdue_home'] ?? 0);

            return [
                'on_vessel' => (int) ($deployment['on_vessel'] ?? 0),
                'ready_to_join' => (int) ($deployment['ready_to_join'] ?? 0),
                'in_home' => (int) ($deployment['in_home'] ?? 0),
                'at_home' => (int) ($deployment['in_home'] ?? 0),
                'needs_update' => $needsUpdate,
                'movement_updates_required' => $needsUpdate,
                'planned_signoffs_due' => $dueSoon,
                'overdue_at_home' => $overdueHome,
                'total' => (int) ($deployment['total'] ?? 0),
            ];
        });
    }

    /**
     * @return array{
     *     published: int,
     *     scheduled: int,
     *     failed_deliveries: int,
     *     total: int,
     *     recent: list<array{id: int, title: string, published_at: string|null, status: string}>
     * }
     */
    public function announcementsSummary(int $companyId, User $user): array
    {
        return $this->rememberCompany($companyId, 'announcements', function () use ($companyId): array {
            $published = (int) Announcement::query()
                ->where('company_id', $companyId)
                ->where('status', AnnouncementStatus::Published)
                ->count();

            $scheduled = (int) Announcement::query()
                ->where('company_id', $companyId)
                ->where('status', AnnouncementStatus::Scheduled)
                ->count();

            $failedDeliveries = (int) DB::table('announcement_deliveries')
                ->where('company_id', $companyId)
                ->where('status', 'failed')
                ->count();

            $recent = Announcement::query()
                ->where('company_id', $companyId)
                ->where('status', AnnouncementStatus::Published)
                ->orderByDesc('published_at')
                ->limit(5)
                ->get(['id', 'title', 'published_at', 'status'])
                ->map(fn (Announcement $a): array => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'published_at' => $a->published_at?->toDateTimeString(),
                    'status' => $a->status->value,
                ])
                ->all();

            return [
                'published' => $published,
                'scheduled' => $scheduled,
                'failed_deliveries' => $failedDeliveries,
                'total' => $published,
                'recent' => $recent,
            ];
        });
    }

    /**
     * @return array{recent: list<array{id: int, causer_name: string, description: string, subject_type: string, created_at: string}>}
     */
    public function auditSummary(int $companyId, User $user): array
    {
        if (! $user->can('audit.view')) {
            return ['recent' => []];
        }

        return $this->rememberCompany($companyId, 'audit', function () use ($companyId): array {
            $logs = Activity::query()
                ->where('company_id', $companyId)
                ->with(['causer:id,name'])
                ->latest('id')
                ->limit(6)
                ->get();

            $recent = ActivityChangePresenter::presentLogs($logs, $companyId)
                ->map(fn (Activity $log): array => [
                    'id' => $log->id,
                    'causer_name' => $log->causer?->name ?? 'System',
                    'description' => $log->description ?? $log->event ?? 'Action',
                    'subject_type' => class_basename((string) $log->subject_type),
                    'created_at' => $log->created_at?->toIso8601String() ?? '',
                ])
                ->values()
                ->all();

            return ['recent' => $recent];
        });
    }

    /**
     * Permission-aware My Attention section items.
     *
     * @return list<array{
     *     key: string,
     *     module: string,
     *     title: string,
     *     description: string|null,
     *     count: int,
     *     severity: 'critical'|'warning'|'info',
     *     href: string,
     *     action_label: string
     * }>
     */
    public function attentionCentre(int $companyId, User $user): array
    {
        return $this->rememberUser($companyId, $user->id, 'attention', function () use ($companyId, $user): array {
            $items = [];
            $timezone = CompanyTimezone::forCompanyId($companyId);
            $today = now($timezone)->toDateString();

            // Expired documents
            if ($user->can('documents.view')) {
                $expiredDocs = (int) EmployeeDocument::query()
                    ->where('company_id', $companyId)
                    ->whereNotNull('expiry_date')
                    ->where('expiry_date', '<', $today)
                    ->count();

                if ($expiredDocs > 0) {
                    $items[] = [
                        'key' => 'expired_documents',
                        'module' => 'Documents',
                        'title' => 'Expired Documents',
                        'description' => sprintf('%d employee documents have passed their expiration date.', $expiredDocs),
                        'count' => $expiredDocs,
                        'severity' => 'critical',
                        'href' => route('organization.documents', ['expiry' => 'expired']),
                        'action_label' => 'Review Documents',
                    ];
                }

                $expiring7Docs = (int) EmployeeDocument::query()
                    ->where('company_id', $companyId)
                    ->whereNotNull('expiry_date')
                    ->where('expiry_date', '>=', $today)
                    ->where('expiry_date', '<=', now($timezone)->addDays(7)->toDateString())
                    ->count();

                if ($expiring7Docs > 0) {
                    $items[] = [
                        'key' => 'expiring_7_documents',
                        'module' => 'Documents',
                        'title' => 'Documents Expiring Soon',
                        'description' => sprintf('%d documents will expire within 7 days.', $expiring7Docs),
                        'count' => $expiring7Docs,
                        'severity' => 'warning',
                        'href' => route('organization.documents', ['expiry' => 'expiring_7']),
                        'action_label' => 'View Expiring',
                    ];
                }
            }

            // Leave requests awaiting approval
            if ($user->can('attendance.leave-requests.approve')) {
                $awaitingQuery = LeaveRequest::query();
                $this->leaveRequestVisibility->applyAwaitingMyApprovalScope($awaitingQuery, $user, $companyId);
                $awaitingCount = $awaitingQuery->count();

                if ($awaitingCount > 0) {
                    $items[] = [
                        'key' => 'leave_approvals',
                        'module' => 'Leave',
                        'title' => 'Leave Requests Awaiting Approval',
                        'description' => sprintf('You have %d leave request(s) waiting for your decision.', $awaitingCount),
                        'count' => $awaitingCount,
                        'severity' => 'warning',
                        'href' => route('attendance.leave-requests.index', ['view' => 'awaiting_my_approval']),
                        'action_label' => 'Review Approvals',
                    ];
                }
            }

            // Contracts ending within 30 days & No contract employees
            if ($user->can('contracts.view')) {
                $summary = $this->contractsSummary($companyId);
                if (($summary['ending_30'] ?? 0) > 0) {
                    $items[] = [
                        'key' => 'contracts_ending_30',
                        'module' => 'Contracts',
                        'title' => 'Contracts Ending Soon',
                        'description' => sprintf('%d employment contract(s) end within 30 days.', $summary['ending_30']),
                        'count' => (int) $summary['ending_30'],
                        'severity' => 'warning',
                        'href' => route('organization.contracts', ['lifecycle' => 'ending_30']),
                        'action_label' => 'View Contracts',
                    ];
                }

                if (($summary['no_contract_employees'] ?? 0) > 0) {
                    $items[] = [
                        'key' => 'no_contract_employees',
                        'module' => 'Contracts',
                        'title' => 'Employees Without Contract',
                        'description' => sprintf('%d active employee(s) have no contract on file.', $summary['no_contract_employees']),
                        'count' => (int) $summary['no_contract_employees'],
                        'severity' => 'critical',
                        'href' => route('organization.contracts.no-contract'),
                        'action_label' => 'Assign Contracts',
                    ];
                }
            }

            // Expired training
            if ($user->can('training.view')) {
                $training = $this->trainingSummary($companyId);
                if (($training['expired'] ?? 0) > 0) {
                    $items[] = [
                        'key' => 'expired_training',
                        'module' => 'Training',
                        'title' => 'Expired Training Certificates',
                        'description' => sprintf('%d training certificates have expired.', $training['expired']),
                        'count' => (int) $training['expired'],
                        'severity' => 'critical',
                        'href' => route('organization.training', ['status' => 'expired']),
                        'action_label' => 'View Certificates',
                    ];
                }
            }

            // Employees missing bank accounts
            if ($user->can('bank_accounts.view')) {
                $bankSummary = $this->bankAccountsSummary($companyId);
                if (($bankSummary['no_account_employees'] ?? 0) > 0) {
                    $items[] = [
                        'key' => 'no_bank_account_employees',
                        'module' => 'Bank Accounts',
                        'title' => 'Employees Missing Bank Details',
                        'description' => sprintf('%d active employee(s) do not have a bank account configured.', $bankSummary['no_account_employees']),
                        'count' => (int) $bankSummary['no_account_employees'],
                        'severity' => 'warning',
                        'href' => route('organization.bank-accounts.no-account'),
                        'action_label' => 'Add Accounts',
                    ];
                }
            }

            // Payroll processing periods
            if ($user->can('payroll.overview.view')) {
                $processing = (int) PayrollPeriod::query()
                    ->where('company_id', $companyId)
                    ->where('status', 'processing')
                    ->count();

                if ($processing > 0) {
                    $items[] = [
                        'key' => 'payroll_processing',
                        'module' => 'Payroll',
                        'title' => 'Payroll Periods Awaiting Action',
                        'description' => sprintf('%d payroll period(s) are currently in processing status.', $processing),
                        'count' => $processing,
                        'severity' => 'warning',
                        'href' => route('payroll.index', ['status' => 'processing']),
                        'action_label' => 'Open Payroll',
                    ];
                }
            }

            // Crew movement updates required
            if ($user->can('crew_operations.overview.view')) {
                $crewSummary = $this->crewSummary($companyId, $user);
                if ($crewSummary['needs_update'] > 0) {
                    $items[] = [
                        'key' => 'crew_updates_needed',
                        'module' => 'Crew Operations',
                        'title' => 'Crew Movement Updates Needed',
                        'description' => sprintf('%d crew member(s) require status or assignment updates.', $crewSummary['needs_update']),
                        'count' => $crewSummary['needs_update'],
                        'severity' => 'critical',
                        'href' => route('organization.crew-assignments.index', ['filter' => 'needs_update']),
                        'action_label' => 'Update Crew',
                    ];
                }
            }

            // Bulk documents signature reviews
            if ($user->can('bulk_documents.signatures.review')) {
                $pendingSignatures = (int) BulkDocumentSignatureRequest::query()
                    ->where('company_id', $companyId)
                    ->where('status', BulkDocumentSignatureRequestStatus::Submitted)
                    ->count();

                if ($pendingSignatures > 0) {
                    $items[] = [
                        'key' => 'pending_signatures_review',
                        'module' => 'Bulk Documents',
                        'title' => 'Document Signatures Needing Review',
                        'description' => sprintf('%d signature review(s) are pending approval.', $pendingSignatures),
                        'count' => $pendingSignatures,
                        'severity' => 'warning',
                        'href' => route('organization.documents'),
                        'action_label' => 'Review Signatures',
                    ];
                }
            }

            return $items;
        });
    }

    /**
     * Self-service personal dashboard for a user's linked employee record.
     *
     * @return array<string, mixed>
     */
    public function personalDashboard(int $companyId, User $user): array
    {
        return $this->rememberUser($companyId, $user->id, 'personal', function () use ($companyId, $user): array {
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->with(['position:id,title', 'department:id,name'])
                ->first();

            $timezone = CompanyTimezone::forCompanyId($companyId);
            $today = now($timezone)->toDateString();

            // Personal announcements for this user regardless of company management permissions
            $myAnnouncements = AnnouncementRecipient::query()
                ->where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->whereHas('announcement', fn ($q) => $q->whereIn('status', [
                    AnnouncementStatus::Published->value,
                    AnnouncementStatus::PartiallyDelivered->value,
                ]))
                ->with(['announcement:id,title,body_html,priority,published_at'])
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (AnnouncementRecipient $recipient): array => [
                    'id' => $recipient->id,
                    'title' => $recipient->announcement?->title ?? '',
                    'preview' => str($recipient->announcement?->body_html ?? '')->stripTags()->limit(100)->toString(),
                    'priority' => $recipient->announcement?->priority?->value ?? 'normal',
                    'published_at' => $recipient->announcement?->published_at?->toIso8601String(),
                    'read_at' => $recipient->read_at?->toIso8601String(),
                    'url' => route('organization.announcements.inbox.show', $recipient),
                ])
                ->values()
                ->all();

            if ($employee === null) {
                return [
                    'has_linked_employee' => false,
                    'employee' => null,
                    'attendance_today' => null,
                    'recent_attendance' => [],
                    'my_leave_requests' => [],
                    'my_leave_balances' => [],
                    'my_expiring_documents' => [],
                    'my_announcements' => $myAnnouncements,
                    'my_payslips' => [],
                ];
            }

            $attendanceTodayRecord = AttendanceRecord::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->where('date', $today)
                ->first();

            $attendanceToday = $attendanceTodayRecord !== null ? [
                'status' => $attendanceTodayRecord->status,
                'clock_in' => $attendanceTodayRecord->clock_in?->toIso8601String(),
                'clock_out' => $attendanceTodayRecord->clock_out?->toIso8601String(),
                'hours_worked' => $attendanceTodayRecord->hours_worked,
            ] : null;

            $recentAttendance = AttendanceRecord::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->orderByDesc('date')
                ->limit(5)
                ->get()
                ->map(fn (AttendanceRecord $r): array => [
                    'id' => $r->id,
                    'date' => $r->date?->toDateString(),
                    'clock_in' => $r->clock_in?->toIso8601String(),
                    'clock_out' => $r->clock_out?->toIso8601String(),
                    'status' => $r->status,
                ])
                ->all();

            $myLeaveRequests = LeaveRequest::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->with('leaveType:id,name')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (LeaveRequest $lr): array => [
                    'id' => $lr->id,
                    'leave_type' => $lr->leaveType?->name ?? 'Leave',
                    'start_date' => $lr->start_date?->toDateString(),
                    'end_date' => $lr->end_date?->toDateString(),
                    'total_days' => $lr->total_days,
                    'status' => $lr->status,
                ])
                ->all();

            $myLeaveBalances = app(LeaveTypeYearBalance::class)
                ->forEmployee($companyId, $employee->id, now($timezone)->year);

            $myExpiringDocuments = EmployeeDocument::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '<=', now($timezone)->addDays(30)->toDateString())
                ->orderBy('expiry_date')
                ->limit(5)
                ->get()
                ->map(fn (EmployeeDocument $doc): array => [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'type' => $doc->type,
                    'expiry_date' => $doc->expiry_date?->toDateString(),
                    'is_expired' => $doc->expiry_date !== null && $doc->expiry_date->toDateString() < $today,
                ])
                ->all();

            $myPayslips = PayrollRecord::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->with('period:id,name,end_date')
                ->latest('id')
                ->limit(3)
                ->get()
                ->map(fn (PayrollRecord $rec): array => [
                    'id' => $rec->id,
                    'period_name' => $rec->period?->name ?? 'Payslip',
                    'net_salary' => $rec->net_salary,
                    'created_at' => $rec->created_at?->toDateString(),
                ])
                ->all();

            return [
                'has_linked_employee' => true,
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'employee_no' => $employee->employee_no,
                    'position' => $employee->position?->title,
                    'department' => $employee->department?->name,
                ],
                'attendance_today' => $attendanceToday,
                'recent_attendance' => $recentAttendance,
                'my_leave_requests' => $myLeaveRequests,
                'my_leave_balances' => $myLeaveBalances,
                'my_expiring_documents' => $myExpiringDocuments,
                'my_announcements' => $myAnnouncements,
                'my_payslips' => $myPayslips,
            ];
        });
    }

    /**
     * Monthly trend points driven by hire_date and termination_date.
     *
     * @return list<array{month: string, headcount: int, new_hires: int, documents: int}>
     */
    public function workforceTrends(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'workforce_trends', function () use ($companyId): array {
            $months = [];
            $rangeStart = now()->subMonths(5)->startOfMonth();
            $rangeEnd = now()->endOfMonth();

            for ($i = 5; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $months[$month->format('Y-m')] = [
                    'month' => $month->format('M'),
                    'start_date' => $month->copy()->startOfMonth()->toDateString(),
                    'end_date' => $month->copy()->endOfMonth()->toDateString(),
                    'new_hires' => 0,
                    'documents' => 0,
                ];
            }

            // Single aggregated hire query (using COALESCE(hire_date, created_at))
            $hireCounts = $this->monthlyCounts(
                Employee::query()->where('company_id', $companyId),
                $rangeStart->toDateString(),
                $rangeEnd->toDateString(),
                'COALESCE(hire_date, DATE(created_at))'
            );

            // Single aggregated termination query
            $termCounts = $this->monthlyCounts(
                Employee::query()->where('company_id', $companyId)->whereNotNull('termination_date'),
                $rangeStart->toDateString(),
                $rangeEnd->toDateString(),
                'termination_date'
            );

            // Single aggregated document query
            $documentCounts = $this->monthlyCounts(
                EmployeeDocument::query()->where('company_id', $companyId),
                $rangeStart->toDateTimeString(),
                $rangeEnd->toDateTimeString(),
                'created_at'
            );

            // Baseline headcount before rangeStart
            $baselineHeadcount = (int) Employee::query()
                ->where('company_id', $companyId)
                ->where(function (Builder $query) use ($rangeStart): void {
                    $query->where('hire_date', '<', $rangeStart->toDateString())
                        ->orWhere(function (Builder $q) use ($rangeStart): void {
                            $q->whereNull('hire_date')
                                ->where('created_at', '<', $rangeStart->toDateTimeString());
                        });
                })
                ->where(function (Builder $query) use ($rangeStart): void {
                    $query->whereNull('termination_date')
                        ->orWhere('termination_date', '>=', $rangeStart->toDateString());
                })
                ->count();

            $points = [];
            $runningHeadcount = $baselineHeadcount;

            foreach ($months as $key => $meta) {
                $newHires = $hireCounts[$key] ?? 0;
                $terms = $termCounts[$key] ?? 0;
                $runningHeadcount += $newHires - $terms;

                $points[] = [
                    'month' => $meta['month'],
                    'headcount' => max(0, $runningHeadcount),
                    'new_hires' => $newHires,
                    'documents' => $documentCounts[$key] ?? 0,
                ];
            }

            return $points;
        });
    }

    /**
     * Department distribution aggregated to top values + 'Other'.
     *
     * @return list<array{name: string, count: int}>
     */
    public function employeesByDepartment(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'employees_by_department', function () use ($companyId): array {
            $all = Employee::query()
                ->where('employees.company_id', $companyId)
                ->active()
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw("COALESCE(departments.name, 'Unassigned') as label")
                ->selectRaw('COUNT(*) as count')
                ->groupByRaw("COALESCE(departments.name, 'Unassigned')")
                ->orderByDesc('count')
                ->get();

            $totalActive = (int) $all->sum('count');
            if ($all->count() <= 7) {
                return $all->map(fn ($row) => [
                    'name' => (string) $row->label,
                    'count' => (int) $row->count,
                ])->all();
            }

            $top = $all->take(6);
            $topSum = (int) $top->sum('count');
            $otherSum = max(0, $totalActive - $topSum);

            $result = $top->map(fn ($row) => [
                'name' => (string) $row->label,
                'count' => (int) $row->count,
            ])->values()->all();

            if ($otherSum > 0) {
                $result[] = ['name' => 'Other', 'count' => $otherSum];
            }

            return $result;
        });
    }

    /**
     * Branch distribution aggregated to top values + 'Other'.
     *
     * @return list<array{name: string, count: int}>
     */
    public function employeesByBranch(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'employees_by_branch', function () use ($companyId): array {
            $all = Employee::query()
                ->where('employees.company_id', $companyId)
                ->active()
                ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
                ->selectRaw("COALESCE(branches.name, 'Unassigned') as label")
                ->selectRaw('COUNT(*) as count')
                ->groupByRaw("COALESCE(branches.name, 'Unassigned')")
                ->orderByDesc('count')
                ->get();

            $totalActive = (int) $all->sum('count');
            if ($all->count() <= 5) {
                return $all->map(fn ($row) => [
                    'name' => (string) $row->label,
                    'count' => (int) $row->count,
                ])->all();
            }

            $top = $all->take(4);
            $topSum = (int) $top->sum('count');
            $otherSum = max(0, $totalActive - $topSum);

            $result = $top->map(fn ($row) => [
                'name' => (string) $row->label,
                'count' => (int) $row->count,
            ])->values()->all();

            if ($otherSum > 0) {
                $result[] = ['name' => 'Other', 'count' => $otherSum];
            }

            return $result;
        });
    }

    /**
     * Sorted by hire_date descending, created_at descending secondary.
     *
     * @return list<array{id: int, name: string, employee_no: string, hired_at: string}>
     */
    public function recentHires(int $companyId): array
    {
        return $this->rememberCompany($companyId, 'recent_hires', function () use ($companyId): array {
            return Employee::query()
                ->where('company_id', $companyId)
                ->active()
                ->orderByRaw('COALESCE(hire_date, DATE(created_at)) DESC')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'name', 'employee_no', 'hire_date', 'created_at'])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'employee_no' => $employee->employee_no,
                    'hired_at' => $employee->hire_date !== null
                        ? $employee->hire_date->format('d M Y')
                        : Carbon::parse($employee->created_at)->format('d M Y'),
                ])
                ->all();
        });
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
        $weekEndExclusive = Carbon::parse($weekEnd, $timezone)->addDay()->toDateString();

        $rows = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->where('date', '>=', $weekStart)
            ->where('date', '<', $weekEndExclusive)
            ->selectRaw('date as attendance_date')
            ->selectRaw('SUM(CASE WHEN clock_in IS NOT NULL THEN 1 ELSE 0 END) as check_ins')
            ->selectRaw('SUM(CASE WHEN clock_out IS NOT NULL THEN 1 ELSE 0 END) as check_outs')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row): string => Carbon::parse((string) $row->attendance_date)->toDateString());

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
     * @return list<array<string, mixed>>
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
     * @param  Builder<Model>  $query
     * @return array<string, int>
     */
    private function monthlyCounts(Builder $query, mixed $rangeStart, mixed $rangeEnd, string $column = 'created_at'): array
    {
        $driver = DB::connection()->getDriverName();
        $monthExpression = $driver === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";

        return $query
            ->whereBetween(DB::raw($column), [$rangeStart, $rangeEnd])
            ->selectRaw("{$monthExpression} as month_key")
            ->selectRaw('COUNT(*) as aggregate')
            ->groupByRaw($monthExpression)
            ->pluck('aggregate', 'month_key')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function rememberCompany(int $companyId, string $part, callable $callback): mixed
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

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function rememberUser(int $companyId, int $userId, string $part, callable $callback): mixed
    {
        if (app()->runningUnitTests() && ! self::$forceCacheInTests) {
            return $callback();
        }

        try {
            return Cache::remember(
                self::userCacheKey($companyId, $userId, $part),
                self::CACHE_TTL_SECONDS,
                $callback,
            );
        } catch (\Throwable) {
            return $callback();
        }
    }
}
