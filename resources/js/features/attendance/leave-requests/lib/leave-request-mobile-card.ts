import type { LeaveRequest } from '../types';

export type LeaveRequestMobileCardModel = {
    title: string;
    subtitle: string;
    startDate: string;
    endDate: string;
    duration: string;
    status: LeaveRequest['status'];
    showApprove: boolean;
    showReject: boolean;
    showCancel: boolean;
    showEdit: boolean;
    showDelete: boolean;
    showAdministrativeDelete: boolean;
    primaryLabel: 'Approve' | 'Open';
};

export function leaveRequestMobileCardModel(
    leaveRequest: LeaveRequest,
): LeaveRequestMobileCardModel {
    const canActOnCurrentStep = Boolean(leaveRequest.can_approve_current_step);
    const isPending = leaveRequest.status === 'pending';
    const showApprove = isPending && canActOnCurrentStep;
    const days = String(leaveRequest.total_days).trim();

    return {
        title: leaveRequest.employee?.name ?? 'Unknown employee',
        subtitle: leaveRequest.leave_type?.name ?? 'Leave',
        startDate: leaveRequest.start_date,
        endDate: leaveRequest.end_date,
        duration: days ? `${days} days` : '',
        status: leaveRequest.status,
        showApprove,
        showReject: showApprove,
        showCancel: Boolean(leaveRequest.can_cancel),
        showEdit: Boolean(leaveRequest.can_edit),
        showDelete: Boolean(leaveRequest.can_delete),
        showAdministrativeDelete: Boolean(
            leaveRequest.can_administratively_delete,
        ),
        primaryLabel: showApprove ? 'Approve' : 'Open',
    };
}
