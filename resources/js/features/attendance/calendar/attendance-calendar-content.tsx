import { router, useForm } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { LeaveRequestFormSheet } from '@/features/attendance/leave-requests/components/leave-request-form-sheet';
import { defaultLeaveRequestFormData } from '@/features/attendance/leave-requests/types';
import type {
    LeaveRequestEmployeeOption,
    LeaveRequestTypeOption,
} from '@/features/attendance/leave-requests/types';
import { CalendarEmployeeSelect } from './components/calendar-employee-select';
import { CalendarSummaryStats } from './components/calendar-summary-stats';
import { CalendarToolbar } from './components/calendar-toolbar';
import { LeaveTypeLegend } from './components/leave-type-legend';
import { YearCalendarGrid } from './components/year-calendar-grid';
import { useCalendarRangeSelection } from './hooks/use-calendar-range-selection';
import type { CalendarDateRange } from './hooks/use-calendar-range-selection';
import { buildLeaveDayMap } from './lib/build-leave-day-map';
import { getCalendarStats } from './lib/calendar-stats';
import type {
    CalendarEmployeeOption,
    CalendarFormLeaveType,
    CalendarLeave,
    CalendarLeaveType,
    CalendarPermissions,
    CalendarSelectedEmployee,
} from './types';

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
    linked_employee_id,
    form_employees,
    form_leave_types,
    can,
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
    linked_employee_id: number | null;
    form_employees: LeaveRequestEmployeeOption[];
    form_leave_types: CalendarFormLeaveType[];
    can: CalendarPermissions;
}) {
    const currentYear = new Date(`${today}T00:00:00`).getFullYear();
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const form = useForm(defaultLeaveRequestFormData());
    const stats = useMemo(
        () => getCalendarStats(approved_leaves, year),
        [approved_leaves, year],
    );
    const leaveDayMap = useMemo(
        () => buildLeaveDayMap(approved_leaves, year),
        [approved_leaves, year],
    );
    const canCreateFromCalendar = can.create && selected_employee_id !== null;
    const showEmptyState =
        selected_employee_id !== null &&
        approved_leaves.length === 0 &&
        pending_request_count === 0;
    const selectedEmployeeLabel =
        selected_employee?.name?.trim() || 'This employee';

    const openCreateSheet = useCallback(
        (range: CalendarDateRange) => {
            if (
                range.start === range.end &&
                (leaveDayMap.get(range.start)?.length ?? 0) > 0
            ) {
                return;
            }

            form.reset();
            form.clearErrors();
            form.setData({
                ...defaultLeaveRequestFormData(),
                employee_id: selected_employee_id ?? '',
                start_date: range.start,
                end_date: range.end,
            });
            setIsSheetOpen(true);
        },
        [form, leaveDayMap, selected_employee_id],
    );

    const { isSelecting, beginSelection, extendSelection, isDateInRange } =
        useCalendarRangeSelection({
            enabled: canCreateFromCalendar,
            onRangeComplete: openCreateSheet,
        });

    const submit = () => {
        if (!form.data.employee_id) {
            form.setError('employee_id', 'Employee is required.');

            return;
        }

        if (!form.data.leave_type_id) {
            form.setError('leave_type_id', 'Leave type is required.');

            return;
        }

        form.post('/attendance/leave-requests', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                setIsSheetOpen(false);
                router.reload({
                    only: [
                        'approved_leaves',
                        'leave_types',
                        'pending_request_count',
                        'employees',
                    ],
                });
            },
        });
    };

    return (
        <Main>
            <PageHeader
                kicker="Attendance"
                title="Leave Calendar"
                description={
                    canCreateFromCalendar
                        ? 'Approved leave for the selected employee. Hover or click colored days for details. Drag across empty days to create a request.'
                        : 'Approved leave for the selected employee. Hover or click colored days for details.'
                }
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
                    <YearCalendarGrid
                        year={year}
                        today={today}
                        approvedLeaves={approved_leaves}
                        canCreate={canCreateFromCalendar}
                        isSelecting={isSelecting}
                        isDateInRange={isDateInRange}
                        onBeginSelection={beginSelection}
                        onExtendSelection={extendSelection}
                    />
                </div>
                <div className="space-y-4 xl:sticky xl:top-6 xl:self-start">
                    {can_select_employee ? (
                        <CalendarEmployeeSelect
                            year={year}
                            employees={employees}
                            selectedEmployeeId={selected_employee_id}
                        />
                    ) : null}
                    <CalendarToolbar
                        year={year}
                        currentYear={currentYear}
                        selectedEmployeeId={selected_employee_id}
                    />
                    <LeaveTypeLegend
                        leaveTypes={leave_types}
                        year={year}
                        showBalance={selected_employee_id !== null}
                    />
                </div>
            </div>

            <LeaveRequestFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                leaveRequest={null}
                employees={form_employees}
                leaveTypes={form_leave_types as LeaveRequestTypeOption[]}
                canApprove={can.approve}
                linkedEmployeeId={linked_employee_id}
                form={form}
                onSubmit={submit}
            />
        </Main>
    );
}
