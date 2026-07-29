import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { LeaveApprovalPolicy } from '../types';

export function LeaveApprovalPolicyDeleteDialog({
    open,
    onOpenChange,
    policy,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    policy: LeaveApprovalPolicy | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete approval policy"
            description={
                policy
                    ? `This will permanently delete “${policy.name}”.`
                    : 'This will permanently delete this approval policy.'
            }
            onConfirm={onConfirm}
        />
    );
}
