import { Head } from '@inertiajs/react';
import { AttendanceCalendarContent } from '@/features/attendance/calendar';
import type { CalendarLeave, CalendarLeaveType } from '@/features/attendance/calendar/types';

export default function AttendanceCalendar({
    year,
    today,
    approved_leaves,
    leave_types,
    pending_request_count,
    linked_employee_id,
}: {
    year: number;
    today: string;
    approved_leaves: CalendarLeave[];
    leave_types: CalendarLeaveType[];
    pending_request_count: number;
    linked_employee_id: number | null;
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
                linked_employee_id={linked_employee_id}
            />
        </>
    );
}
