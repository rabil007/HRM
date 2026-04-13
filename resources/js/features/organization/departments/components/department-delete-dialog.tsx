import { router } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { Department } from '../types';

export function DepartmentDeleteDialog({
    open,
    onOpenChange,
    department,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    department: Department | null;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete department"
            description={
                department
                    ? `This will permanently delete “${department.name}”.`
                    : 'This will permanently delete this department.'
            }
            onConfirm={() => {
                if (!department) {
                    return;
                }

                router.delete(`/organization/departments/${department.id}`, {
                    onFinish: () => onOpenChange(false),
                });
            }}
        />
    );
}

