<?php

namespace App\Support\Dashboard;

use App\Models\User;
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

        // Always-present: personal summary, self-service personal dashboard, attention items, and action capabilities.
        $props['personal_summary'] = DashboardPersonalSummary::for($user, $companyId);
        $props['personal_dashboard'] = $this->analytics->personalDashboard($companyId, $user);
        // #region agent log
        @file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-a22a17.log', json_encode(['sessionId' => 'a22a17', 'runId' => 'post-fix', 'hypothesisId' => 'A', 'location' => 'DashboardComposer.php:36', 'message' => 'personal_dashboard composed', 'data' => ['payslip_count' => count($props['personal_dashboard']['my_payslips'] ?? []), 'first_period_name' => $props['personal_dashboard']['my_payslips'][0]['period_name'] ?? null], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion
        $props['attention_items'] = $this->analytics->attentionCentre($companyId, $user);
        $props['can'] = $this->can($user);

        if ($user->can('employees.view')) {
            $props['employee_analytics'] = $this->analytics->workforceSummary($companyId);
            $props['organization_snapshot'] = $this->analytics->organizationSummary($companyId);
        }

        if ($user->can('documents.view')) {
            $docs = $this->analytics->documentSummary($companyId);
            $props['document_compliance'] = $docs['document_compliance'];
            $props['document_health'] = $docs['document_health'];
        }

        if ($user->can('attendance.overview.view')) {
            $props['attendance_analytics'] = $this->analytics->attendanceSummary($companyId);
        }

        if ($user->can('attendance.leave-requests.view') || $user->can('attendance.overview.view')) {
            $props['leave_summary'] = $this->analytics->leaveSummary($companyId, $user);
        }

        if ($user->can('contracts.view')) {
            $props['contracts_summary'] = $this->analytics->contractsSummary($companyId);
        }

        if ($user->can('training.view')) {
            $props['training_summary'] = $this->analytics->trainingSummary($companyId);
        }

        if ($user->can('bank_accounts.view')) {
            $props['bank_accounts_summary'] = $this->analytics->bankAccountsSummary($companyId);
        }

        if ($user->can('crew_operations.overview.view')) {
            $props['crew_summary'] = $this->analytics->crewSummary($companyId, $user);
        }

        if ($user->can('payroll.overview.view')) {
            $props['payroll_summary'] = $this->analytics->payrollSummary($companyId, $user);
            // #region agent log
            @file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-a22a17.log', json_encode(['sessionId' => 'a22a17', 'runId' => 'post-fix', 'hypothesisId' => 'B', 'location' => 'DashboardComposer.php:75', 'message' => 'payroll_summary composed', 'data' => $props['payroll_summary'], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
            // #endregion
        }

        if ($user->can('announcements.view')) {
            $props['announcements_summary'] = $this->analytics->announcementsSummary($companyId, $user);
        }

        if ($user->can('audit.view')) {
            $props['audit_summary'] = $this->analytics->auditSummary($companyId, $user);
        }

        // #region agent log
        @file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-a22a17.log', json_encode(['sessionId' => 'a22a17', 'runId' => 'post-fix', 'hypothesisId' => 'C', 'location' => 'DashboardComposer.php:86', 'message' => 'primary() completed', 'data' => ['prop_keys' => array_keys($props)], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

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
     * @return array<string, bool>
     */
    private function can(User $user): array
    {
        return [
            'employees_create' => $user->can('employees.create'),
            'employees_export' => $user->can('employees.export'),
            'documents_upload' => $user->can('documents.upload'),
            'contracts_create' => $user->can('contracts.create'),
            'attendance_leave_approve' => $user->can('attendance.leave-requests.approve'),
            'payroll_periods_create' => $user->can('payroll.periods.create'),
            'payroll_periods_approve' => $user->can('payroll.periods.approve'),
            'crew_planning_create' => $user->can('crew_operations.planning.create'),
            'announcements_publish' => $user->can('announcements.publish'),
            'signatures_review' => $user->can('bulk_documents.signatures.review'),
            'view_audit' => $user->can('audit.view'),
        ];
    }
}
