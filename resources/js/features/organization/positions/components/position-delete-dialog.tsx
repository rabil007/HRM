import { router } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { Position } from '../types';

export function PositionDeleteDialog({
    open,
    onOpenChange,
    position,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    position: Position | null;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete position"
            description={position ? `This will permanently delete “${position.title}”.` : 'This will permanently delete this position.'}
            onConfirm={() => {
                if (!position) {
                    return;
                }

                router.delete(`/organization/positions/${position.id}`, {
                    onFinish: () => onOpenChange(false),
                });
            }}
        />
    );
}

