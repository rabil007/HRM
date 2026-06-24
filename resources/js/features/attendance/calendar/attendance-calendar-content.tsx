import { useMemo } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { CalendarEmployeeSelect } from './components/calendar-employee-select';
import { CalendarSummaryStats } from './components/calendar-summary-stats';
import { CalendarToolbar } from './components/calendar-toolbar';
import { LeaveTypeLegend } from './components/leave-type-legend';
import { YearCalendarGrid } from './components/year-calendar-grid';
import { getCalendarStats } from './lib/calendar-stats';
import type { CalendarEmployeeOption, CalendarLeave, CalendarLeaveType, CalendarSelectedEmployee } from './types';

export function AttendanceCalendarContent({
    year,
    today,
    approved_leaves,
    leave_types,
    pending_request_count,
    selected_employee_id,
    selected_employee,
    employees,
    can_select_employee,
}: {
    year: number;
    today: string;
    approved_leaves: CalendarLeave[];
    leave_types: CalendarLeaveType[];
    pending_request_count: number;
    selected_employee_id: number | null;
    selected_employee: CalendarSelectedEmployee | null;
    employees: CalendarEmployeeOption[];
    can_select_employee: boolean;
}) {
    const currentYear = new Date(`${today}T00:00:00`).getFullYear();
    const stats = useMemo(() => getCalendarStats(approved_leaves, year), [approved_leaves, year]);
    const showEmptyState =
        selected_employee_id !== null &&
        approved_leaves.length === 0 &&
        pending_request_count === 0;
    const selectedEmployeeLabel = selected_employee?.name?.trim() || 'This employee';

    return (
        <Main>
            <PageHeader
                kicker="Attendance"
                title="Leave Calendar"
                description="Approved leave for the selected employee."
            />

            <div className="mb-6">
                <CalendarSummaryStats
                    year={year}
                    requestCount={stats.requestCount}
                    pendingRequestCount={pending_request_count}
                    leaveDays={stats.leaveDays}
                />
            </div>

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_18rem]">
                <div className="space-y-4">
                    {showEmptyState ? (
                        <EmptyState
                            title="No leave requests"
                            description={`${selectedEmployeeLabel} has no approved or pending leave requests in ${year}.`}
                        />
                    ) : null}
                    <YearCalendarGrid year={year} today={today} approvedLeaves={approved_leaves} />
                </div>
                <div className="space-y-4 xl:sticky xl:top-6 xl:self-start">
                    {can_select_employee ? (
                        <CalendarEmployeeSelect
                            year={year}
                            employees={employees}
                            selectedEmployeeId={selected_employee_id}
                        />
                    ) : null}
                    <CalendarToolbar year={year} currentYear={currentYear} selectedEmployeeId={selected_employee_id} />
                    <LeaveTypeLegend
                        leaveTypes={leave_types}
                        year={year}
                        showBalance={selected_employee_id !== null}
                    />
                </div>
            </div>
        </Main>
    );
}
