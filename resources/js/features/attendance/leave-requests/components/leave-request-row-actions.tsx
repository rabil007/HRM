import { Ban, Check, Eye, Pencil, Trash2, X } from 'lucide-react';
import { TableRowActions } from '@/components/table-row-actions';
import type { TableRowActionItem } from '@/components/table-row-actions';
import type { LeaveRequest, LeaveRequestPermissions } from '../types';

export function LeaveRequestRowActions({
    leaveRequest,
    can,
    onEdit,
    onDelete,
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
    onApprove: (leaveRequest: LeaveRequest) => void;
    onReject: (leaveRequest: LeaveRequest) => void;
    onCancel: (leaveRequest: LeaveRequest) => void;
    className?: string;
    wrapped?: boolean;
}) {
    const isPending = leaveRequest.status === 'pending';
    const canModify = isPending && can.update;
    const canRemove = (isPending || leaveRequest.status === 'cancelled') && can.delete;

    const actions: TableRowActionItem[] = [
        {
            label: 'View',
            icon: Eye,
            href: `/attendance/leave-requests/${leaveRequest.id}`,
        },
        {
            label: 'Approve',
            icon: Check,
            variant: 'success',
            onClick: () => onApprove(leaveRequest),
            hidden: !(isPending && can.approve),
        },
        {
            label: 'Reject',
            icon: X,
            variant: 'danger',
            onClick: () => onReject(leaveRequest),
            hidden: !(isPending && can.approve),
        },
        {
            label: 'Cancel',
            icon: Ban,
            onClick: () => onCancel(leaveRequest),
            hidden: !(isPending && can.update),
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
    ];

    const rowActions = <TableRowActions actions={actions} className={className} />;

    if (!wrapped) {
        return rowActions;
    }

    return (
        <div className="flex items-center justify-end gap-1 rounded-xl border border-border/60 bg-muted/30 p-1.5 backdrop-blur-xl dark:border-white/6 dark:bg-white/4">
            {rowActions}
        </div>
    );
}
