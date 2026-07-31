<?php

namespace App\Support\Dashboard;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Support\CrewOperations\CrewOperationsDashboardAnalytics;
use App\Support\Payroll\PayrollOverviewSummary;
use Inertia\Inertia;

/**
 * Composes the dashboard Inertia props for a given user and company.
 *
 * Each module section is only included when the user holds the relevant
 * permission. This keeps the controller thin and avoids leaking data the user
 * has no right to see.
 */
final class DashboardComposer
{
    public function __construct(
        private readonly DashboardAnalytics $analytics,
        private readonly CrewOperationsDashboardAnalytics $crewAnalytics,
    ) {}

    /**
     * Build all immediately-resolved props.
     *
     * Deferred (secondary) props are registered separately in the controller
     * so Inertia can stream them after the initial render.
     *
     * @return array<string, mixed>
     */
    public function primary(User $user, int $companyId): array
    {
        $props = [];

        // Always-present: personal summary and action capabilities.
        $props['personal_summary'] = DashboardPersonalSummary::for($user, $companyId);
        $props['can'] = $this->can($user);

        if ($user->can('employees.view')) {
            $primary = $this->analytics->primaryForCompany($companyId);
            $props['employee_analytics'] = $primary['employee_analytics'];
            $props['organization_snapshot'] = $primary['organization_snapshot'];
            $props['document_health'] = $primary['document_health'] ?? [];
            $props['attendance_analytics'] = $primary['attendance_analytics'] ?? null;
        }

        if ($user->can('documents.view')) {
            $primary = $this->analytics->primaryForCompany($companyId);
            $props['document_compliance'] = $primary['document_compliance'];
            if (! isset($props['document_health'])) {
                $props['document_health'] = $primary['document_health'] ?? [];
            }
        }

        // attendance_analytics requires employees.view (already fetched above).
        // Provide a standalone path for users who only have attendance.overview.view
        // but not employees.view — they still get the attendance KPIs.
        if (! $user->can('employees.view') && $user->can('attendance.overview.view')) {
            $primary = $this->analytics->primaryForCompany($companyId);
            $props['attendance_analytics'] = $primary['attendance_analytics'] ?? null;
        }

        if ($user->can('crew_operations.overview.view')) {
            $props['crew_summary'] = $this->crewSummary($companyId, $user);
        }

        if ($user->can('payroll.overview.view')) {
            $props['payroll_summary'] = $this->payrollSummary($companyId);
        }

        if ($user->can('announcements.view')) {
            $props['announcements_summary'] = $this->announcementsSummary($companyId);
        }

        return $props;
    }

    /**
     * Register the deferred (secondary) props on the Inertia response.
     * Only registers a deferred prop when the user has the required permission.
     *
     * @return array<string, mixed>
     */
    public function deferred(User $user, int $companyId): array
    {
        if (! $user->can('employees.view')) {
            return [];
        }

        return [
            'workforce_trends' => Inertia::defer(
                fn (): array => $this->analytics->workforceTrends($companyId),
                'secondary',
            ),
            'employees_by_department' => Inertia::defer(
                fn (): array => $this->analytics->employeesByDepartment($companyId),
                'secondary',
            ),
            'employees_by_branch' => Inertia::defer(
                fn (): array => $this->analytics->employeesByBranch($companyId),
                'secondary',
            ),
            'recent_hires' => Inertia::defer(
                fn (): array => $this->analytics->recentHires($companyId),
                'secondary',
            ),
        ];
    }

    /**
     * @return array{
     *     employees_create: bool,
     *     employees_export: bool,
     *     documents_upload: bool,
     *     view_audit: bool
     * }
     */
    private function can(User $user): array
    {
        return [
            'employees_create' => $user->can('employees.create'),
            'employees_export' => $user->can('employees.export'),
            'documents_upload' => $user->can('documents.upload'),
            'view_audit' => $user->can('audit.view'),
        ];
    }

    /**
     * @return array{on_vessel: int, in_home: int, needs_update: int, total: int}
     */
    private function crewSummary(int $companyId, User $user): array
    {
        $full = $this->crewAnalytics->forCompany($companyId, $user);
        $deployment = $full['deployment_summary'] ?? [];

        return [
            'on_vessel' => (int) ($deployment['on_vessel'] ?? 0),
            'in_home' => (int) ($deployment['in_home'] ?? 0),
            'needs_update' => (int) ($full['alert_counts']['needs_update'] ?? 0),
            'total' => (int) ($deployment['total'] ?? 0),
        ];
    }

    /**
     * @return array{
     *     draft_periods: int,
     *     processing_periods: int,
     *     last_paid_period_name: string|null,
     *     last_paid_period_total: float|null
     * }
     */
    private function payrollSummary(int $companyId): array
    {
        $full = PayrollOverviewSummary::forCompany($companyId);

        return [
            'draft_periods' => $full['draft_periods'],
            'processing_periods' => $full['processing_periods'],
            'last_paid_period_name' => $full['last_paid_period_name'],
            'last_paid_period_total' => $full['last_paid_period_total'],
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     recent: list<array{id: int, title: string, published_at: string|null, status: string}>
     * }
     */
    private function announcementsSummary(int $companyId): array
    {
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

        $total = Announcement::query()
            ->where('company_id', $companyId)
            ->where('status', AnnouncementStatus::Published)
            ->count();

        return [
            'total' => $total,
            'recent' => $recent,
        ];
    }
}
