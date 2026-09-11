import { Calendar, UserMinus, Clock, AlertCircle } from 'lucide-react';
import { index as leaveApprovalsIndex } from '@/routes/attendance/leave-approvals';
import { index as myLeaveIndex } from '@/routes/attendance/my-leave';
import { DashboardMetricCard } from '../components/dashboard-metric-card';
import { DashboardSection } from '../components/dashboard-section';
import type { LeaveDashboardSummary } from '../dashboard-types';

type LeaveSectionProps = {
    summary?: LeaveDashboardSummary;
};

export function LeaveSection({ summary }: LeaveSectionProps) {
    if (!summary) {
        return null;
    }

    return (
        <DashboardSection
            title="Leave Management"
            description="Employees on leave, upcoming schedules, and approval backlog"
            icon={Calendar}
            actionLabel="My leave"
            actionHref={myLeaveIndex.url()}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardMetricCard
                    title="On Leave Today"
                    value={summary.on_leave_today}
                    subtitle={`${summary.upcoming_this_week} starting this week`}
                    icon={UserMinus}
                    iconColor="text-blue-500"
                    href={myLeaveIndex.url()}
                />

                <DashboardMetricCard
                    title="Pending Requests"
                    value={summary.pending_requests}
                    subtitle="Company-wide pending requests"
                    icon={Clock}
                    iconColor="text-amber-500"
                    href={leaveApprovalsIndex.url({
                        query: { status: 'pending', scope: 'all' },
                    })}
                />

                <DashboardMetricCard
                    title="Awaiting My Approval"
                    value={summary.awaiting_my_approval}
                    subtitle={
                        summary.oldest_pending_date
                            ? `Oldest: ${summary.oldest_pending_date}`
                            : 'No backlog'
                    }
                    icon={AlertCircle}
                    iconColor="text-purple-500"
                    badgeText={
                        summary.awaiting_my_approval > 0
                            ? 'Action Needed'
                            : undefined
                    }
                    badgeVariant={
                        summary.awaiting_my_approval > 0 ? 'warning' : 'default'
                    }
                    href={leaveApprovalsIndex.url()}
                />

                <DashboardMetricCard
                    title="Upcoming This Week"
                    value={summary.upcoming_this_week}
                    subtitle="Scheduled leave starting soon"
                    icon={Calendar}
                    iconColor="text-emerald-500"
                    href={myLeaveIndex.url()}
                />
            </div>
        </DashboardSection>
    );
}
