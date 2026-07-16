export type CalendarLeaveType = {
    id: number;
    name: string;
    code: string;
    color: string | null;
    entitled_days: number | null;
    used_days: number | null;
    pending_days: number | null;
    remaining_days: number | null;
};

export type CalendarEmployeeOption = {
    id: number;
    employee_no: string | null;
    name: string;
};

export type CalendarSelectedEmployee = CalendarEmployeeOption;

export type CalendarFormLeaveType = {
    id: number;
    name: string;
    code: string;
    color: string | null;
};

export type CalendarPermissions = {
    create: boolean;
    approve: boolean;
};

export type CalendarLeave = {
    id: number;
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
    } | null;
    leave_type: CalendarLeaveType | null;
    start_date: string;
    end_date: string;
};

export type CalendarDayCell = {
    date: string;
    day: number;
    inMonth: boolean;
};

export type TimelineEvent = {
    time: string;
    status: 'checkIn' | 'checkOut';
    device_name: string | null;
};

export type TodayTimelineSummary = {
    clock_in: string | null;
    clock_out: string | null;
    is_complete: boolean;
    is_on_leave: boolean;
};

export type TodayTimeline = {
    date: string;
    events: TimelineEvent[];
    summary: TodayTimelineSummary;
} | null;
