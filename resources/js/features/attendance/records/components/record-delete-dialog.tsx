import { router } from '@inertiajs/react';
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
import type { AttendanceRecord } from '../types';

export function RecordDeleteDialog({
    open,
    onOpenChange,
    record,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    record: AttendanceRecord | null;
}) {
    if (!record) {
        return null;
    }

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Delete attendance record?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        This will permanently delete the record for{' '}
                        {record.employee?.name ?? 'this employee'} on{' '}
                        {record.date}.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={() =>
                            router.delete(`/attendance/records/${record.id}`, {
                                preserveScroll: true,
                                onSuccess: () => onOpenChange(false),
                            })
                        }
                    >
                        Delete
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
