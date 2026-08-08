import {
    Clock,
    CheckCircle2,
    AlertCircle,
    CalendarX,
    UserCheck,
} from 'lucide-react';
import { overview as attendanceOverview } from '@/routes/attendance';
import { index as attendanceRecordsIndex } from '@/routes/attendance/records';
import { DashboardMetricCard } from '../components/dashboard-metric-card';
import { DashboardSection } from '../components/dashboard-section';
import type { AttendanceAnalytics } from '../dashboard-types';

type AttendanceSectionProps = {
    analytics?: AttendanceAnalytics;
};

export function AttendanceSection({ analytics }: AttendanceSectionProps) {
    if (!analytics) {
        return null;
    }

    return (
        <DashboardSection
            title="Today's Attendance"
            description="Real-time attendance & check-in activity for active workforce"
            icon={Clock}
            actionLabel="View Attendance Log"
            actionHref={attendanceOverview.url()}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardMetricCard
                    title="Employees Present"
                    value={analytics.present_today}
                    subtitle={`Out of ${analytics.active_employees} active employees`}
                    icon={UserCheck}
                    iconColor="text-emerald-500"
                    badgeText="Distinct"
                    badgeVariant="success"
                    href={attendanceRecordsIndex.url({
                        query: { date: 'today' },
                    })}
                />

                <DashboardMetricCard
                    title="Check-Ins / Outs"
                    value={`${analytics.check_ins_today} / ${analytics.check_outs_today}`}
                    subtitle={`${analytics.events_today || analytics.attendance_events_today || 0} total events today`}
                    icon={CheckCircle2}
                    iconColor="text-blue-500"
                    href={attendanceRecordsIndex.url({
                        query: { date: 'today' },
                    })}
                />

                <DashboardMetricCard
                    title="Late Arrivals"
                    value={analytics.late_today}
                    subtitle="Arrived after shift threshold"
                    icon={AlertCircle}
                    iconColor="text-amber-500"
                    badgeVariant={
                        analytics.late_today > 0 ? 'warning' : 'default'
                    }
                    href={attendanceRecordsIndex.url({
                        query: { status: 'late' },
                    })}
                />

                <DashboardMetricCard
                    title="Absent Today"
                    value={analytics.absent_today}
                    subtitle="Unexcused absence"
                    icon={CalendarX}
                    iconColor="text-rose-500"
                    badgeVariant={
                        analytics.absent_today > 0 ? 'danger' : 'default'
                    }
                    href={attendanceRecordsIndex.url({
                        query: { status: 'absent' },
                    })}
                />
            </div>
        </DashboardSection>
    );
}
