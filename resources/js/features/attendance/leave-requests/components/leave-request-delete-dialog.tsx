import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { LeaveRequest } from '../types';

export function LeaveRequestDeleteDialog({
    open,
    onOpenChange,
    leaveRequest,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    leaveRequest: LeaveRequest | null;
    onConfirm: () => void;
}) {
    const label = leaveRequest?.employee?.name ?? 'this leave request';

    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete leave request"
            description={`This will permanently delete the leave request for “${label}”.`}
            onConfirm={onConfirm}
        />
    );
}
