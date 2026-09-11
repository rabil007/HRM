import type { SavedView } from '@/lib/saved-views';
import type { PaginationMeta } from '@/types/pagination';
import { LeaveRequestsContent } from './leave-requests-content';
import type {
    LeaveRequest,
    LeaveRequestEmployeeOption,
    LeaveRequestFilters,
    LeaveRequestPermissions,
    LeaveRequestTypeOption,
} from './types';

export function LeaveApprovalsContent(props: {
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
    linkedEmployeeId: number | null;
    can: LeaveRequestPermissions;
    saved_views?: SavedView[];
}) {
    return <LeaveRequestsContent {...props} listMode="approvals" />;
}
