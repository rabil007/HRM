import { Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
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

export function ConfirmDeleteDocumentDialog({
    open,
    onOpenChange,
    title = 'Delete document?',
    description = 'The file and all metadata will be permanently removed. This cannot be undone.',
    confirmLabel = 'Delete',
    confirmDisabled = false,
    onConfirm,
    icon,
    contentClassName,
    cancelClassName,
    confirmClassName,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title?: string;
    description?: ReactNode;
    confirmLabel?: string;
    confirmDisabled?: boolean;
    onConfirm: () => void;
    icon?: ReactNode;
    contentClassName?: string;
    cancelClassName?: string;
    confirmClassName?: string;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className={contentClassName ?? 'sm:max-w-sm'}>
                <AlertDialogHeader>
                    <div className="mb-1 flex items-center gap-3">
                        {icon ?? (
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-red-400">
                                <Trash2 className="size-4" />
                            </span>
                        )}
                        <AlertDialogTitle>{title}</AlertDialogTitle>
                    </div>
                    <AlertDialogDescription>{description}</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel
                        className={
                            cancelClassName ??
                            'border-border bg-muted/50 text-muted-foreground hover:bg-accent hover:text-foreground dark:border-white/10 dark:bg-white/5 dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-zinc-100'
                        }
                    >
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className={confirmClassName ?? 'bg-red-600 text-white hover:bg-red-500'}
                        disabled={confirmDisabled}
                        onClick={onConfirm}
                    >
                        {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
