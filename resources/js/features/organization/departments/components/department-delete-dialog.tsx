import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { Department } from '../types';

export function DepartmentDeleteDialog({
    open,
    onOpenChange,
    department,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    department: Department | null;
    onConfirm: () => void;
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
            onConfirm={onConfirm}
        />
    );
}

