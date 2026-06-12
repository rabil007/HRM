import { useMemo } from 'react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { CalendarSummaryStats } from './components/calendar-summary-stats';
import { CalendarToolbar } from './components/calendar-toolbar';
import { LeaveTypeLegend } from './components/leave-type-legend';
import { YearCalendarGrid } from './components/year-calendar-grid';
import { getCalendarStats } from './lib/calendar-stats';
import type { CalendarLeave, CalendarLeaveType } from './types';

export function AttendanceCalendarContent({
    year,
    today,
    approved_leaves,
    leave_types,
    pending_request_count,
}: {
    year: number;
    today: string;
    approved_leaves: CalendarLeave[];
    leave_types: CalendarLeaveType[];
    pending_request_count: number;
}) {
    const currentYear = new Date(`${today}T00:00:00`).getFullYear();
    const stats = useMemo(() => getCalendarStats(approved_leaves, year), [approved_leaves, year]);

    return (
        <Main>
            <PageHeader
                kicker="Attendance"
                title="Leave Calendar"
                description="A year-at-a-glance view of approved leave across your team."
            />

            <div className="mb-6 space-y-4">
                <CalendarToolbar year={year} currentYear={currentYear} />
                <CalendarSummaryStats
                    year={year}
                    requestCount={stats.requestCount}
                    pendingRequestCount={pending_request_count}
                    leaveDays={stats.leaveDays}
                    typeCount={stats.typeCount}
                />
            </div>

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_18rem]">
                <YearCalendarGrid year={year} today={today} approvedLeaves={approved_leaves} />
                <div className="xl:sticky xl:top-6 xl:self-start">
                    <LeaveTypeLegend leaveTypes={leave_types} year={year} />
                </div>
            </div>
        </Main>
    );
}
