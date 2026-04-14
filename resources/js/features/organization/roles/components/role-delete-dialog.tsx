import { router } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { Role } from '../types';

export function RoleDeleteDialog({
    open,
    onOpenChange,
    role,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    role: Role | null;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete role"
            description={role ? `This will permanently delete “${role.name}”.` : 'This will permanently delete this role.'}
            confirmText={role?.is_system ? 'System role' : undefined}
            confirmButtonClassName={role?.is_system ? 'rounded-xl h-11 px-6 opacity-50 pointer-events-none' : undefined}
            onConfirm={() => {
                if (!role || role.is_system) {
                    return;
                }

                router.delete(`/organization/roles/${role.id}`, {
                    onFinish: () => onOpenChange(false),
                });
            }}
        />
    );
}

