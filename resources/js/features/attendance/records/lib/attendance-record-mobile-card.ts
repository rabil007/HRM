import type { AttendanceRecord, AttendanceRecordPermissions } from '../types';

export type AttendanceRecordMobileCardModel = {
    title: string;
    subtitle: string | null;
    hoursLine: string;
    status: string;
    attention: string | null;
    showEmployeeIdentity: boolean;
    showEdit: boolean;
    showDelete: boolean;
};

export function attendanceRecordMobileCardModel(
    record: AttendanceRecord,
    can: Pick<AttendanceRecordPermissions, 'update' | 'delete' | 'manage'>,
): AttendanceRecordMobileCardModel {
    const showEmployeeIdentity = can.manage;
    const clockIn = record.clock_in;
    const clockOut = record.clock_out;
    const hours =
        record.hours_worked !== null && record.hours_worked !== ''
            ? `${record.hours_worked} hrs`
            : '';

    return {
        title: showEmployeeIdentity
            ? (record.employee?.name ?? 'Unknown employee')
            : record.date,
        subtitle: showEmployeeIdentity ? record.date : null,
        hoursLine: [
            clockIn || clockOut
                ? `${clockIn ?? '—'} – ${clockOut ?? '—'}`
                : null,
            hours,
        ]
            .filter((value): value is string => Boolean(value))
            .join(' · '),
        status: record.status,
        attention: attendanceAttention(record),
        showEmployeeIdentity,
        showEdit: can.update,
        showDelete: can.delete,
    };
}

function attendanceAttention(record: AttendanceRecord): string | null {
    if (record.late_minutes > 0) {
        return `Late ${record.late_minutes} min`;
    }

    if (record.clock_in && !record.clock_out) {
        return 'Missing check-out';
    }

    return null;
}
