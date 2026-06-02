import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { EmployeeProfileTemplate } from '../types';

export function TemplateDeleteDialog({
    open,
    onOpenChange,
    template,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    template: EmployeeProfileTemplate | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete template"
            description={
                template
                    ? `Delete "${template.name}"? Employees already linked keep their data; only the template configuration is removed.`
                    : 'This will permanently delete this template.'
            }
            onConfirm={onConfirm}
        />
    );
}
