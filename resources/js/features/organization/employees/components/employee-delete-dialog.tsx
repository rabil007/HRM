import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { Employee } from '../types';

export function EmployeeDeleteDialog({
    open,
    onOpenChange,
    employee,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee: Employee | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete employee"
            description={employee ? `This will permanently delete ${employee.name}.` : 'This will permanently delete this employee.'}
            confirmText="Delete"
            onConfirm={onConfirm}
        />
    );
}

