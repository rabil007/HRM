import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { User } from '../types';

export function UserDeleteDialog({
    open,
    onOpenChange,
    user,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: User | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete user"
            description={user ? `This will permanently delete “${user.email}”.` : 'This will permanently delete this user.'}
            onConfirm={onConfirm}
        />
    );
}

