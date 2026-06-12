import { Head } from '@inertiajs/react';
import { AttendanceTypesContent } from '@/features/attendance/types';
import type { LeaveType } from '@/features/attendance/types/types';
import type { PaginationMeta } from '@/types/pagination';

export default function AttendanceTypes({
    leave_types,
    pagination,
    search,
}: {
    leave_types: LeaveType[];
    pagination: PaginationMeta;
    search: string;
}) {
    return (
        <>
            <Head title="Attendance types" />
            <AttendanceTypesContent leave_types={leave_types} pagination={pagination} search={search} />
        </>
    );
}
