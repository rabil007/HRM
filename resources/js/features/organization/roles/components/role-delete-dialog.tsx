import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { Role } from '../types';

export function RoleDeleteDialog({
    open,
    onOpenChange,
    role,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    role: Role | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete role"
            description={role ? `This will permanently delete “${role.name}”.` : 'This will permanently delete this role.'}
            onConfirm={onConfirm}
        />
    );
}

