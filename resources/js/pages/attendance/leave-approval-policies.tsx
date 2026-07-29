import { Head } from '@inertiajs/react';
import { LeaveApprovalPoliciesContent } from '@/features/attendance/leave-approval-policies';
import type {
    LeaveApprovalApproverTypeOption,
    LeaveApprovalPolicy,
    LeaveApprovalPolicyEmployeeOption,
    LeaveApprovalPolicyPermissions,
} from '@/features/attendance/leave-approval-policies/types';
import type { PaginationMeta } from '@/types/pagination';

export default function LeaveApprovalPolicies({
    policies,
    pagination,
    search,
    approver_types,
    employees,
    can,
}: {
    policies: LeaveApprovalPolicy[];
    pagination: PaginationMeta;
    search: string;
    approver_types: LeaveApprovalApproverTypeOption[];
    employees: LeaveApprovalPolicyEmployeeOption[];
    can: LeaveApprovalPolicyPermissions;
}) {
    return (
        <>
            <Head title="Approval policies" />
            <LeaveApprovalPoliciesContent
                policies={policies}
                pagination={pagination}
                search={search}
                approver_types={approver_types}
                employees={employees}
                can={can}
            />
        </>
    );
}
