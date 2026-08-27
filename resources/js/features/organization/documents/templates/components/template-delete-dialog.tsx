import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { CustomTemplate } from '../types';

export function TemplateDeleteDialog({
    open,
    onOpenChange,
    template,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    template: CustomTemplate | null;
    onConfirm: () => void;
}) {
    if (!template) {
        return null;
    }

    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete Document Template"
            description={
                <>
                    Are you sure you want to delete{' '}
                    <span className="font-semibold text-foreground">
                        {template.name}
                    </span>
                    ? This action cannot be undone.
                </>
            }
            confirmText="Delete Template"
            onConfirm={onConfirm}
        />
    );
}
