import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { Branch } from '../types';

export function BranchDeleteDialog({
    open,
    onOpenChange,
    branch,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    branch: Branch | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete branch"
            description={
                <>
                    This will permanently delete{' '}
                    <span className="font-semibold text-foreground">
                        {branch?.name ?? 'this branch'}
                    </span>
                    .
                </>
            }
            confirmText="Confirm"
            onConfirm={onConfirm}
            contentClassName="border-white/5 bg-black/60 backdrop-blur-3xl"
            footerClassName="gap-3 sm:gap-3"
        />
    );
}

