import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
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
import { toast } from '@/lib/toast';

export type EmployeeRecordDeleteDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    destroyUrl: string | null;
    reloadOptions: {
        preserveScroll: boolean;
        only: string[];
    };
    successMessage: string;
};

export function EmployeeRecordDeleteDialog({
    open,
    onOpenChange,
    title,
    description,
    destroyUrl,
    reloadOptions,
    successMessage,
}: EmployeeRecordDeleteDialogProps): ReactElement {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="sm:max-w-sm">
                <AlertDialogHeader>
                    <div className="mb-1 flex items-center gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-red-400">
                            <Trash2 className="size-4" />
                        </span>
                        <AlertDialogTitle>{title}</AlertDialogTitle>
                    </div>
                    <AlertDialogDescription>{description}</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100">
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className="bg-red-600 text-white hover:bg-red-500"
                        onClick={() => {
                            if (!destroyUrl) {
                                return;
                            }

                            router.delete(destroyUrl, {
                                ...reloadOptions,
                                onSuccess: () => {
                                    onOpenChange(false);
                                    toast.success(successMessage);
                                },
                            });
                        }}
                    >
                        Remove
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
