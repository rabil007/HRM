import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { LeaveType } from '../types';

export function LeaveTypeDeleteDialog({
    open,
    onOpenChange,
    leaveType,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    leaveType: LeaveType | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete type"
            description={
                leaveType
                    ? `This will permanently delete “${leaveType.name}”.`
                    : 'This will permanently delete this type.'
            }
            onConfirm={onConfirm}
        />
    );
}
