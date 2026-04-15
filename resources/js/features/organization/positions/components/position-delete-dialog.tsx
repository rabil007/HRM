import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { Position } from '../types';

export function PositionDeleteDialog({
    open,
    onOpenChange,
    position,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    position: Position | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete position"
            description={position ? `This will permanently delete “${position.title}”.` : 'This will permanently delete this position.'}
            onConfirm={onConfirm}
        />
    );
}

