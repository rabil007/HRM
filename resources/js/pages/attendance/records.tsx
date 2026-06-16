import { Head } from '@inertiajs/react';
import { AttendanceRecordsContent } from '@/features/attendance/records';
import type {
    AttendanceRecord,
    AttendanceRecordFilters,
    AttendanceRecordPermissions,
} from '@/features/attendance/records/types';
import type { PaginationMeta } from '@/types/pagination';

export default function AttendanceRecords({
    records,
    pagination,
    search,
    filters,
    employees,
    status_options,
    source_options,
    linked_employee_id,
    can,
}: {
    records: AttendanceRecord[];
    pagination: PaginationMeta;
    search: string;
    filters: AttendanceRecordFilters & { search?: string };
    employees: Array<{ id: number; employee_no: string | null; name: string }>;
    status_options: Array<{ value: string; label: string }>;
    source_options: Array<{ value: string; label: string }>;
    linked_employee_id: number | null;
    can: AttendanceRecordPermissions;
}) {
    return (
        <>
            <Head title="Attendance Records" />
            <AttendanceRecordsContent
                records={records}
                pagination={pagination}
                search={search}
                filters={filters}
                employees={employees}
                status_options={status_options}
                source_options={source_options}
                linkedEmployeeId={linked_employee_id}
                can={can}
            />
        </>
    );
}
