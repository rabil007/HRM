import { Head } from '@inertiajs/react';
import { AttendanceCalendarContent } from '@/features/attendance/calendar';
import type { CalendarEmployeeOption, CalendarLeave, CalendarLeaveType, CalendarSelectedEmployee } from '@/features/attendance/calendar/types';

export default function AttendanceCalendar({
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
    return (
        <>
            <Head title="Attendance Calendar" />
            <AttendanceCalendarContent
                year={year}
                today={today}
                approved_leaves={approved_leaves}
                leave_types={leave_types}
                pending_request_count={pending_request_count}
                selected_employee_id={selected_employee_id}
                selected_employee={selected_employee}
                employees={employees}
                can_select_employee={can_select_employee}
            />
        </>
    );
}
