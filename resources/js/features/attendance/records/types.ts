export type AttendanceRecordEmployee = {
    id: number;
    employee_no: string | null;
    name: string;
};

export type AttendanceRecord = {
    id: number;
    employee: AttendanceRecordEmployee | null;
    date: string;
    clock_in: string | null;
    clock_out: string | null;
    hours_worked: string | number | null;
    overtime_hours: string | number | null;
    late_minutes: number;
    source: string;
    status: string;
    notes: string | null;
};

export type AttendanceRecordFilters = {
    date_from: string;
    date_to: string;
    employee_id: string;
    status: string;
    source: string;
};

export const EMPTY_ATTENDANCE_RECORD_FILTERS: AttendanceRecordFilters = {
    date_from: '',
    date_to: '',
    employee_id: '',
    status: '',
    source: '',
};

export type AttendanceRecordPermissions = {
    create: boolean;
    update: boolean;
    delete: boolean;
    manage: boolean;
};

export type AttendanceRecordFormData = {
    employee_id: string;
    date: string;
    clock_in: string;
    clock_out: string;
    hours_worked: string;
    overtime_hours: string;
    late_minutes: string;
    status: string;
    notes: string;
};

export const defaultAttendanceRecordFormData = (
    linkedEmployeeId: number | null = null,
): AttendanceRecordFormData => ({
    employee_id: linkedEmployeeId ? String(linkedEmployeeId) : '',
    date: new Date().toISOString().slice(0, 10),
    clock_in: '',
    clock_out: '',
    hours_worked: '',
    overtime_hours: '0',
    late_minutes: '0',
    status: 'present',
    notes: '',
});

export const attendanceRecordToFormData = (record: AttendanceRecord): AttendanceRecordFormData => ({
    employee_id: record.employee ? String(record.employee.id) : '',
    date: record.date,
    clock_in: record.clock_in ? record.clock_in.slice(0, 16) : '',
    clock_out: record.clock_out ? record.clock_out.slice(0, 16) : '',
    hours_worked: record.hours_worked !== null ? String(record.hours_worked) : '',
    overtime_hours: record.overtime_hours !== null ? String(record.overtime_hours) : '0',
    late_minutes: String(record.late_minutes ?? 0),
    status: record.status,
    notes: record.notes ?? '',
});
