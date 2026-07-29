import { Ban, Check, Eye, Pencil, ShieldAlert, Trash2, X } from 'lucide-react';
import { show as leaveRequestShow } from '@/actions/App/Http/Controllers/Attendance/LeaveRequestController';
import { TableRowActions } from '@/components/table-row-actions';
import type { TableRowActionItem } from '@/components/table-row-actions';
import type { LeaveRequest, LeaveRequestPermissions } from '../types';

export function LeaveRequestRowActions({
    leaveRequest,
    onEdit,
    onDelete,
    onAdministrativeDelete,
    onApprove,
    onReject,
    onCancel,
    className,
    wrapped = false,
}: {
    leaveRequest: LeaveRequest;
    can: LeaveRequestPermissions;
    onEdit: (leaveRequest: LeaveRequest) => void;
    onDelete: (leaveRequest: LeaveRequest) => void;
    onAdministrativeDelete?: (leaveRequest: LeaveRequest) => void;
    onApprove: (leaveRequest: LeaveRequest) => void;
    onReject: (leaveRequest: LeaveRequest) => void;
    onCancel: (leaveRequest: LeaveRequest) => void;
    className?: string;
    wrapped?: boolean;
}) {
    const canModify = Boolean(leaveRequest.can_edit);
    const canRemove = Boolean(leaveRequest.can_delete);
    const canAdministrativelyDelete = Boolean(
        leaveRequest.can_administratively_delete,
    );
    const canCancelRequest = Boolean(leaveRequest.can_cancel);
    const canActOnCurrentStep = Boolean(leaveRequest.can_approve_current_step);
    const isPending = leaveRequest.status === 'pending';

    const actions: TableRowActionItem[] = [
        {
            label: 'View',
            icon: Eye,
            href: leaveRequestShow.url(leaveRequest.id),
        },
        {
            label: 'Approve',
            icon: Check,
            variant: 'success',
            onClick: () => onApprove(leaveRequest),
            hidden: !(isPending && canActOnCurrentStep),
        },
        {
            label: 'Reject',
            icon: X,
            variant: 'danger',
            onClick: () => onReject(leaveRequest),
            hidden: !(isPending && canActOnCurrentStep),
        },
        {
            label: 'Cancel',
            icon: Ban,
            onClick: () => onCancel(leaveRequest),
            hidden: !canCancelRequest,
        },
        {
            label: 'Edit',
            icon: Pencil,
            onClick: () => onEdit(leaveRequest),
            hidden: !canModify,
        },
        {
            label: 'Delete',
            icon: Trash2,
            variant: 'danger',
            onClick: () => onDelete(leaveRequest),
            hidden: !canRemove,
        },
        {
            label: 'Void and remove',
            icon: ShieldAlert,
            variant: 'danger',
            onClick: () => onAdministrativeDelete?.(leaveRequest),
            hidden: !canAdministrativelyDelete || !onAdministrativeDelete,
        },
    ];

    const rowActions = (
        <TableRowActions actions={actions} className={className} />
    );

    if (!wrapped) {
        return rowActions;
    }

    return (
        <div className="flex items-center justify-end gap-1 rounded-xl border border-border/60 bg-muted/30 p-1.5 backdrop-blur-xl dark:border-white/6 dark:bg-white/4">
            {rowActions}
        </div>
    );
}
