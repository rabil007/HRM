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
import { Trash2 } from 'lucide-react';
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
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="border-white/10 bg-black/90 backdrop-blur-2xl">
                <AlertDialogHeader>
                    <AlertDialogTitle className="text-xl font-bold bg-destructive/10 text-destructive p-3 rounded-lg flex items-center gap-2 mb-2 w-fit">
                        <Trash2 className="h-5 w-5" />
                        Delete Company
                    </AlertDialogTitle>
                    <AlertDialogDescription className="text-base text-muted-foreground font-medium">
                        Are you absolutely sure you want to delete{' '}
                        <span className="text-foreground font-bold">{company?.name}</span>? This action cannot be
                        undone and will remove all associated data.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter className="mt-6 gap-3">
                    <AlertDialogCancel className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 h-12 px-6">
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={onConfirm}
                        className="rounded-xl bg-destructive hover:bg-destructive/90 h-12 px-6"
                    >
                        Confirm Delete
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

