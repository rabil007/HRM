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
            title="Move employee to Deleted?"
            description={
                employee
                    ? `The employee will be removed from the active employee directory, but their Employee No. will remain reserved and the record can be restored later from Employees > Deleted. Employees included in pay runs cannot be deleted.`
                    : 'The employee will be removed from the active employee directory, but their Employee No. will remain reserved and the record can be restored later from Employees > Deleted. Employees included in pay runs cannot be deleted.'
            }
            confirmText="Move to Deleted"
            onConfirm={onConfirm}
        />
    );
}
