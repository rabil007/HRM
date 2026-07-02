import { Trash2 } from 'lucide-react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { Company } from '../types';

export function CompanyDeleteDialog({
    open,
    onOpenChange,
    company,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    company: Company | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title={
                <span className="mb-2 flex w-fit items-center gap-2 rounded-lg bg-destructive/10 p-3 text-xl font-bold text-destructive">
                    <Trash2 className="h-5 w-5" />
                    Delete Company
                </span>
            }
            description={
                <span className="text-base font-medium text-muted-foreground">
                    Are you absolutely sure you want to delete{' '}
                    <span className="font-bold text-foreground">
                        {company?.name}
                    </span>
                    ? This action cannot be undone and will remove all
                    associated data.
                </span>
            }
            confirmText="Confirm Delete"
            onConfirm={onConfirm}
            contentClassName="glass-card"
            footerClassName="mt-6 gap-3"
            cancelButtonClassName="glass-card rounded-xl hover:bg-accent h-12 px-6 text-muted-foreground"
            confirmButtonClassName="rounded-xl bg-destructive hover:bg-destructive/90 h-12 px-6"
        />
    );
}
