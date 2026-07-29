export type LeaveApprovalApproverTypeValue =
    | 'department_manager'
    | 'parent_manager'
    | 'hr_approver'
    | 'specific_employee';

export type LeaveApprovalApproverTypeOption = {
    value: LeaveApprovalApproverTypeValue | string;
    label: string;
    requires_employee: boolean;
    allows_employee_override: boolean;
};

export type LeaveApprovalPolicyEmployeeOption = {
    id: number;
    employee_no: string | null;
    name: string | null;
    employee_status?: string | null;
    has_linked_user?: boolean;
    linked_user_active?: boolean;
    has_leave_request_approve_permission?: boolean;
    actionable?: boolean;
    warnings?: string[];
};

export type LeaveApprovalPolicyStep = {
    id: number;
    sequence: number;
    approver_type: string;
    approver_type_label: string | null;
    approver_employee_id: number | null;
    is_required: boolean;
};

export type LeaveApprovalPolicy = {
    id: number;
    name: string;
    description: string | null;
    is_default: boolean;
    status: 'active' | 'inactive';
    departments_count: number;
    steps: LeaveApprovalPolicyStep[];
    created_at: string | null;
    updated_at: string | null;
};

export type LeaveApprovalPolicyStepFormData = {
    id?: number;
    approver_type: string;
    approver_employee_id: number | '';
    is_required: boolean;
};

export type LeaveApprovalPolicyFormData = {
    name: string;
    description: string;
    is_default: boolean;
    status: 'active' | 'inactive';
    steps: LeaveApprovalPolicyStepFormData[];
};

export type LeaveApprovalPolicyPermissions = {
    create: boolean;
    update: boolean;
    delete: boolean;
    manage_settings: boolean;
};

export const defaultLeaveApprovalPolicyStepFormData =
    (): LeaveApprovalPolicyStepFormData => ({
        approver_type: 'department_manager',
        approver_employee_id: '',
        is_required: true,
    });

export const defaultLeaveApprovalPolicyFormData =
    (): LeaveApprovalPolicyFormData => ({
        name: '',
        description: '',
        is_default: false,
        status: 'active',
        steps: [defaultLeaveApprovalPolicyStepFormData()],
    });

export function leaveApprovalPolicyToFormData(
    policy: LeaveApprovalPolicy,
): LeaveApprovalPolicyFormData {
    return {
        name: policy.name,
        description: policy.description ?? '',
        is_default: policy.is_default,
        status: policy.status,
        steps:
            policy.steps.length > 0
                ? policy.steps.map((step) => ({
                      id: step.id,
                      approver_type: step.approver_type,
                      approver_employee_id: step.approver_employee_id ?? '',
                      is_required: step.is_required,
                  }))
                : [defaultLeaveApprovalPolicyStepFormData()],
    };
}
