export type LeaveRequestListMode = 'mine' | 'approvals';

export type LeaveRequestStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'cancelled';

export type LeaveRequestScope =
    | 'my'
    | 'awaiting_my_approval'
    | 'assigned_to_me'
    | 'all';

export type LeaveRequestEmployeeOption = {
    id: number;
    employee_no: string | null;
    name: string;
};

export type LeaveRequestTypeOption = {
    id: number;
    name: string;
    code: string;
    color: string | null;
};

export type LeaveTypeYearBalance = {
    id: number;
    name: string;
    code: string;
    color: string | null;
    base_entitlement_days: number;
    carried_days: number;
    total_available_days: number;
    entitled_days: number;
    opening_used_days: number;
    used_days: number;
    total_used_days: number;
    pending_days: number;
    remaining_days: number;
};

export type LeaveRequestStatusCounts = {
    all: number;
    pending: number;
    approved: number;
    rejected: number;
    cancelled: number;
};

export type LeaveRequestAttachment = {
    path: string;
    name: string;
    size: number;
    mime: string | null;
    url: string;
};

export type LeaveRequestApprovalStatus =
    | 'waiting'
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'skipped'
    | 'cancelled';

export type LeaveRequestApproval = {
    id: number;
    sequence: number;
    approver_type: string;
    approver_type_label: string | null;
    status: LeaveRequestApprovalStatus | string;
    is_required: boolean;
    acted_at: string | null;
    comments: string | null;
    approver_employee: {
        id: number;
        name: string;
        employee_no: string | null;
    } | null;
    approver_user: { id: number; name: string } | null;
    source_department: { id: number; name: string } | null;
    policy_id?: number | null;
    policy_name?: string | null;
    policy_step_label?: string | null;
};

export type LeaveReassignmentApproverCandidate = {
    id: number;
    employee_no: string | null;
    name: string | null;
    actionable: boolean;
};

export type LeaveRequest = {
    id: number;
    employee: LeaveRequestEmployeeOption | null;
    leave_type: LeaveRequestTypeOption | null;
    start_date: string;
    end_date: string;
    total_days: string | number;
    reason: string | null;
    status: LeaveRequestStatus;
    approval_mode?: 'all_required' | 'any_required';
    approval_mode_label?: string;
    rejection_reason: string | null;
    cancellation_reason: string | null;
    decided_at: string | null;
    approver: { id: number; name: string } | null;
    created_at: string | null;
    attachments: LeaveRequestAttachment[];
    can_approve_current_step?: boolean;
    can_edit?: boolean;
    can_cancel?: boolean;
    can_delete?: boolean;
    can_administratively_delete?: boolean;
    can_reassign_current_approval?: boolean;
    approvals?: LeaveRequestApproval[];
};

export type LeaveRequestFormData = {
    employee_id: number | '';
    leave_type_id: number | '';
    start_date: string;
    end_date: string;
    reason: string;
    attachment: File | null;
    remove_attachment: boolean;
};

export type LeaveRequestFilters = {
    status: '' | LeaveRequestStatus;
    employee_id: string;
    leave_type_id: string;
    scope?: LeaveRequestScope;
};

export type LeaveRequestPermissions = {
    create: boolean;
    update: boolean;
    delete: boolean;
    approve: boolean;
    view_all: boolean;
    reassign_approval?: boolean;
};

export const defaultLeaveRequestFormData = (): LeaveRequestFormData => ({
    employee_id: '',
    leave_type_id: '',
    start_date: '',
    end_date: '',
    reason: '',
    attachment: null,
    remove_attachment: false,
});

export function leaveRequestToFormData(
    leaveRequest: LeaveRequest,
): LeaveRequestFormData {
    return {
        employee_id: leaveRequest.employee?.id ?? '',
        leave_type_id: leaveRequest.leave_type?.id ?? '',
        start_date: leaveRequest.start_date,
        end_date: leaveRequest.end_date,
        reason: leaveRequest.reason ?? '',
        attachment: null,
        remove_attachment: false,
    };
}
