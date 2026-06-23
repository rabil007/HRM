import { router } from '@inertiajs/react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { destroy } from '@/actions/App/Http/Controllers/Payroll/SalaryAdjustmentController';
import type { SalaryAdjustment } from '../types';

export function SalaryAdjustmentDeleteDialog({
    open,
    onOpenChange,
    adjustment,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    adjustment: SalaryAdjustment | null;
}) {
    const handleDelete = () => {
        if (!adjustment) {
            return;
        }

        router.delete(destroy.url(adjustment.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete salary adjustment?</AlertDialogTitle>
                    <AlertDialogDescription>
                        This will permanently remove the pending adjustment
                        {adjustment?.employee?.name ? ` for ${adjustment.employee.name}` : ''}.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl">Cancel</AlertDialogCancel>
                    <Button variant="destructive" className="rounded-xl" onClick={handleDelete}>
                        Delete
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
