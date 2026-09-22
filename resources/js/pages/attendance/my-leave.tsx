import { Head } from '@inertiajs/react';
import { MyLeaveContent } from '@/features/attendance/leave-requests/my-leave-content';
import type {
    LeaveRequest,
    LeaveRequestEmployeeOption,
    LeaveRequestFilters,
    LeaveRequestPermissions,
    LeaveRequestTypeOption,
} from '@/features/attendance/leave-requests/types';
import type { SavedView } from '@/lib/saved-views';
import type { PaginationMeta } from '@/types/pagination';

export default function MyLeave({
    leave_requests,
    pagination,
    status_counts,
    search,
    filters,
    employees,
    leave_types,
    linked_employee_id,
    linked_employee_attendance_leave_enabled = true,
    can,
    saved_views = [],
}: {
    leave_requests: LeaveRequest[];
    pagination: PaginationMeta;
    status_counts: {
        all: number;
        pending: number;
        approved: number;
        rejected: number;
        cancelled: number;
    };
    search: string;
    filters: LeaveRequestFilters;
    employees: LeaveRequestEmployeeOption[];
    leave_types: LeaveRequestTypeOption[];
    linked_employee_id: number | null;
    linked_employee_attendance_leave_enabled?: boolean;
    can: LeaveRequestPermissions;
    saved_views?: SavedView[];
}) {
    return (
        <>
            <Head title="My leave" />
            <MyLeaveContent
                leave_requests={leave_requests}
                pagination={pagination}
                status_counts={status_counts}
                search={search}
                filters={filters}
                employees={employees}
                leave_types={leave_types}
                linkedEmployeeId={linked_employee_id}
                linkedEmployeeAttendanceLeaveEnabled={
                    linked_employee_attendance_leave_enabled
                }
                can={can}
                saved_views={saved_views}
            />
        </>
    );
}
