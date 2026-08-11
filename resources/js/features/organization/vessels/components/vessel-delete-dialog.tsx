import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { VesselRow } from '../types';

export function VesselDeleteDialog({
    open,
    onOpenChange,
    vessel,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    vessel: VesselRow | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete vessel"
            description={
                <>
                    This will permanently delete{' '}
                    <span className="font-semibold text-foreground">
                        {vessel?.name ?? 'this vessel'}
                    </span>
                    . Vessels used on sea service or crew assignment records
                    cannot be deleted.
                </>
            }
            confirmText="Confirm"
            onConfirm={onConfirm}
            contentClassName="glass-card"
            footerClassName="gap-3 sm:gap-3"
        />
    );
}
