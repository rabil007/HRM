export type CalendarLeaveType = {
    id: number;
    name: string;
    code: string;
    color: string | null;
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
