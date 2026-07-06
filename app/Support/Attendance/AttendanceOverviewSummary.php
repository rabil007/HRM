<?php

namespace App\Support\Attendance;

use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class AttendanceOverviewSummary
{
    /**
     * @return array{
     *     this_month_total: int,
     *     this_month_present: int,
     *     this_month_absent: int,
     *     this_month_late: int,
     *     this_month_half_day: int,
     *     this_month_holiday: int,
     *     this_month_weekend: int,
     *     this_month_avg_hours: float|null,
     *     this_month_total_overtime_hours: float,
     *     this_month_total_late_minutes: int,
     *     ytd_total_records: int,
     *     ytd_total_overtime_hours: float,
     *     ytd_total_late_minutes: int,
     *     source_breakdown: array{manual: int, biometric: int, mobile: int},
     *     status_breakdown: list<array{name: string, count: int}>,
     *     monthly_trend: list<array{month: string, total: int, present: int, absent: int, late: int, avg_hours: float, total_overtime: float, late_minutes: int}>,
     *     leave_pending: int,
     *     leave_approved: int,
     *     leave_rejected: int,
     *     leave_cancelled: int,
     *     leave_approved_days_this_month: float,
     *     leave_approved_days_ytd: float,
     *     leave_monthly_trend: list<array{month: string, pending: int, approved: int, total_days: float}>,
     *     recent_pending_leaves: list<array{id: int, employee_name: string, leave_type: string|null, start_date: string, end_date: string, total_days: string|float, created_at: string|null}>,
     * }
     */
    public static function forCompany(int $companyId): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();
        $yearStart = $now->copy()->startOfYear()->toDateString();

        // ── Attendance Records – this month ──────────────────────────────────

        /** @var Collection<int, \stdClass> $monthlyStatusCounts */
        $monthlyStatusCounts = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $thisMonthTotal = (int) $monthlyStatusCounts->sum();
        $thisMonthPresent = (int) ($monthlyStatusCounts['present'] ?? 0);
        $thisMonthAbsent = (int) ($monthlyStatusCounts['absent'] ?? 0);
        $thisMonthLate = (int) ($monthlyStatusCounts['late'] ?? 0);
        $thisMonthHalfDay = (int) ($monthlyStatusCounts['half_day'] ?? 0);
        $thisMonthHoliday = (int) ($monthlyStatusCounts['holiday'] ?? 0);
        $thisMonthWeekend = (int) ($monthlyStatusCounts['weekend'] ?? 0);

        $thisMonthAggregates = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->selectRaw('AVG(hours_worked) as avg_hours, SUM(overtime_hours) as total_overtime, SUM(late_minutes) as total_late_minutes')
            ->first();

        $thisMonthAvgHours = $thisMonthAggregates && $thisMonthAggregates->avg_hours !== null
            ? round((float) $thisMonthAggregates->avg_hours, 2)
            : null;
        $thisMonthTotalOvertimeHours = $thisMonthAggregates ? round((float) ($thisMonthAggregates->total_overtime ?? 0), 2) : 0.0;
        $thisMonthTotalLateMinutes = $thisMonthAggregates ? (int) ($thisMonthAggregates->total_late_minutes ?? 0) : 0;

        // ── YTD ──────────────────────────────────────────────────────────────

        $ytdAggregates = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereBetween('date', [$yearStart, $now->toDateString()])
            ->selectRaw('COUNT(*) as total, SUM(overtime_hours) as total_overtime, SUM(late_minutes) as total_late_minutes')
            ->first();

        $ytdTotalRecords = $ytdAggregates ? (int) ($ytdAggregates->total ?? 0) : 0;
        $ytdTotalOvertimeHours = $ytdAggregates ? round((float) ($ytdAggregates->total_overtime ?? 0), 2) : 0.0;
        $ytdTotalLateMinutes = $ytdAggregates ? (int) ($ytdAggregates->total_late_minutes ?? 0) : 0;

        // ── Source breakdown (this month) ─────────────────────────────────────

        /** @var Collection<int, \stdClass> $sourceCounts */
        $sourceCounts = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->selectRaw('source, COUNT(*) as count')
            ->groupBy('source')
            ->pluck('count', 'source');

        $sourceBreakdown = [
            'manual' => (int) ($sourceCounts['manual'] ?? 0),
            'biometric' => (int) ($sourceCounts['biometric'] ?? 0),
            'mobile' => (int) ($sourceCounts['mobile'] ?? 0),
        ];

        // ── Status breakdown for charts ───────────────────────────────────────

        $statusBreakdown = collect(AttendanceRecord::statusOptions())
            ->map(fn (string $status) => [
                'name' => ucfirst(str_replace('_', ' ', $status)),
                'count' => (int) ($monthlyStatusCounts[$status] ?? 0),
            ])
            ->values()
            ->all();

        // ── Monthly trend – last 6 months ─────────────────────────────────────

        $months = collect(range(5, 0))->map(fn (int $i) => $now->copy()->subMonths($i));

        $monthlyTrend = $months->map(function (Carbon $month) use ($companyId): array {
            $start = $month->copy()->startOfMonth()->toDateString();
            $end = $month->copy()->endOfMonth()->toDateString();

            $statusRows = AttendanceRecord::query()
                ->where('company_id', $companyId)
                ->whereBetween('date', [$start, $end])
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            $aggs = AttendanceRecord::query()
                ->where('company_id', $companyId)
                ->whereBetween('date', [$start, $end])
                ->selectRaw('COUNT(*) as total, AVG(hours_worked) as avg_hours, SUM(overtime_hours) as total_overtime, SUM(late_minutes) as late_minutes')
                ->first();

            return [
                'month' => $month->format('M Y'),
                'total' => $aggs ? (int) ($aggs->total ?? 0) : 0,
                'present' => (int) ($statusRows['present'] ?? 0),
                'absent' => (int) ($statusRows['absent'] ?? 0),
                'late' => (int) ($statusRows['late'] ?? 0),
                'avg_hours' => $aggs && $aggs->avg_hours !== null ? round((float) $aggs->avg_hours, 2) : 0.0,
                'total_overtime' => $aggs ? round((float) ($aggs->total_overtime ?? 0), 2) : 0.0,
                'late_minutes' => $aggs ? (int) ($aggs->late_minutes ?? 0) : 0,
            ];
        })->values()->all();

        // ── Leave Requests ────────────────────────────────────────────────────

        /** @var Collection<int, \stdClass> $leaveStatusCounts */
        $leaveStatusCounts = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $leavePending = (int) ($leaveStatusCounts['pending'] ?? 0);
        $leaveApproved = (int) ($leaveStatusCounts['approved'] ?? 0);
        $leaveRejected = (int) ($leaveStatusCounts['rejected'] ?? 0);
        $leaveCancelled = (int) ($leaveStatusCounts['cancelled'] ?? 0);

        $leaveApprovedDaysThisMonth = (float) LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->where(function ($q) use ($monthStart, $monthEnd): void {
                $q->whereBetween('start_date', [$monthStart, $monthEnd])
                    ->orWhereBetween('end_date', [$monthStart, $monthEnd]);
            })
            ->sum('total_days');

        $leaveApprovedDaysYtd = (float) LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->where(function ($q) use ($yearStart, $now): void {
                $q->whereBetween('start_date', [$yearStart, $now->toDateString()])
                    ->orWhereBetween('end_date', [$yearStart, $now->toDateString()]);
            })
            ->sum('total_days');

        // ── Leave monthly trend – last 6 months ───────────────────────────────

        $leaveMonthlyTrend = $months->map(function (Carbon $month) use ($companyId): array {
            $start = $month->copy()->startOfMonth()->toDateString();
            $end = $month->copy()->endOfMonth()->toDateString();

            $rows = LeaveRequest::query()
                ->where('company_id', $companyId)
                ->whereBetween('start_date', [$start, $end])
                ->selectRaw('status, COUNT(*) as count, SUM(total_days) as total_days')
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            return [
                'month' => $month->format('M Y'),
                'pending' => (int) ($rows['pending']->count ?? 0),
                'approved' => (int) ($rows['approved']->count ?? 0),
                'total_days' => round((float) ($rows->sum('total_days') ?? 0), 1),
            ];
        })->values()->all();

        // ── Recent pending leave requests ─────────────────────────────────────

        $recentPendingLeaves = LeaveRequest::query()
            ->with(['employee:id,name,employee_no', 'leaveType:id,name'])
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (LeaveRequest $lr) => [
                'id' => $lr->id,
                'employee_name' => $lr->employee?->name ?? '—',
                'leave_type' => $lr->leaveType?->name,
                'start_date' => $lr->start_date?->toDateString(),
                'end_date' => $lr->end_date?->toDateString(),
                'total_days' => $lr->total_days,
                'created_at' => $lr->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'this_month_total' => $thisMonthTotal,
            'this_month_present' => $thisMonthPresent,
            'this_month_absent' => $thisMonthAbsent,
            'this_month_late' => $thisMonthLate,
            'this_month_half_day' => $thisMonthHalfDay,
            'this_month_holiday' => $thisMonthHoliday,
            'this_month_weekend' => $thisMonthWeekend,
            'this_month_avg_hours' => $thisMonthAvgHours,
            'this_month_total_overtime_hours' => $thisMonthTotalOvertimeHours,
            'this_month_total_late_minutes' => $thisMonthTotalLateMinutes,
            'ytd_total_records' => $ytdTotalRecords,
            'ytd_total_overtime_hours' => $ytdTotalOvertimeHours,
            'ytd_total_late_minutes' => $ytdTotalLateMinutes,
            'source_breakdown' => $sourceBreakdown,
            'status_breakdown' => $statusBreakdown,
            'monthly_trend' => $monthlyTrend,
            'leave_pending' => $leavePending,
            'leave_approved' => $leaveApproved,
            'leave_rejected' => $leaveRejected,
            'leave_cancelled' => $leaveCancelled,
            'leave_approved_days_this_month' => $leaveApprovedDaysThisMonth,
            'leave_approved_days_ytd' => $leaveApprovedDaysYtd,
            'leave_monthly_trend' => $leaveMonthlyTrend,
            'recent_pending_leaves' => $recentPendingLeaves,
        ];
    }
}
