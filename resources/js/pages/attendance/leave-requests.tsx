import { Head } from '@inertiajs/react';
import { LeaveRequestsContent } from '@/features/attendance/leave-requests';
import type {
    LeaveRequest,
    LeaveRequestEmployeeOption,
    LeaveRequestFilters,
    LeaveRequestPermissions,
    LeaveRequestTypeOption,
} from '@/features/attendance/leave-requests/types';
import type { PaginationMeta } from '@/types/pagination';

export default function LeaveRequests({
    leave_requests,
    pagination,
    status_counts,
    search,
    filters,
    employees,
    leave_types,
    linked_employee_id,
    can,
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
    can: LeaveRequestPermissions;
}) {
    return (
        <>
            <Head title="Leave Requests Management" />
            <LeaveRequestsContent
                leave_requests={leave_requests}
                pagination={pagination}
                status_counts={status_counts}
                search={search}
                filters={filters}
                employees={employees}
                leave_types={leave_types}
                linkedEmployeeId={linked_employee_id}
                can={can}
            />
        </>
    );
}
