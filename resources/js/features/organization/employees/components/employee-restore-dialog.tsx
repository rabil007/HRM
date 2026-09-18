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
import { Button } from '@/components/ui/button';
import type { DeletedEmployee } from '../types';

export function EmployeeRestoreDialog({
    open,
    onOpenChange,
    employee,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee: DeletedEmployee | null;
    onConfirm: () => void;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Restore employee?</AlertDialogTitle>
                    <AlertDialogDescription>
                        {employee
                            ? `This will restore Employee No. ${employee.employee_no} and return the employee to the Employees directory.`
                            : 'This will restore the employee and return them to the Employees directory.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel asChild>
                        <Button
                            variant="ghost"
                            className="h-11 rounded-xl px-6 text-muted-foreground"
                        >
                            Cancel
                        </Button>
                    </AlertDialogCancel>
                    <AlertDialogAction asChild>
                        <Button
                            className="h-11 rounded-xl px-6"
                            onClick={onConfirm}
                        >
                            Restore
                        </Button>
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
