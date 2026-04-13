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
import { Button } from '@/components/ui/button';

export function ConfirmDeleteDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    onConfirm,
    contentClassName,
    footerClassName,
    cancelButtonClassName,
    confirmButtonClassName,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    description: ReactNode;
    confirmText?: string;
    cancelText?: string;
    onConfirm: () => void;
    contentClassName?: string;
    footerClassName?: string;
    cancelButtonClassName?: string;
    confirmButtonClassName?: string;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className={contentClassName}>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>{description}</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter className={footerClassName}>
                    <AlertDialogCancel asChild>
                        <Button
                            variant="ghost"
                            className={cancelButtonClassName ?? 'rounded-xl h-11 px-6 text-muted-foreground'}
                        >
                            {cancelText}
                        </Button>
                    </AlertDialogCancel>
                    <AlertDialogAction asChild>
                        <Button
                            variant="destructive"
                            className={confirmButtonClassName ?? 'rounded-xl h-11 px-6'}
                            onClick={onConfirm}
                        >
                            {confirmText}
                        </Button>
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

