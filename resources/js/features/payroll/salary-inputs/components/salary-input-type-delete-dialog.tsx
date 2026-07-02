import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import type { SalaryInputTypeRecord } from '../types';

export function SalaryInputTypeDeleteDialog({
    open,
    onOpenChange,
    salaryInputType,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    salaryInputType: SalaryInputTypeRecord | null;
    onConfirm: () => void;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Delete salary input type?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {salaryInputType
                            ? `"${salaryInputType.name}" will be removed. Types used in pay runs cannot be deleted.`
                            : 'This type will be removed permanently.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction onClick={onConfirm}>
                        Delete
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
