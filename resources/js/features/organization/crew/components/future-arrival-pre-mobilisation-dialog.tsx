import type { ReactElement } from 'react';
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
import { Spinner } from '@/components/ui/spinner';
import { formatDisplayDate } from '@/lib/format-date';

export function FutureArrivalPreMobilisationDialog({
    open,
    plannedArrivalDate,
    processing,
    onOpenChange,
    onConfirmPreMobilisation,
}: {
    open: boolean;
    plannedArrivalDate: string;
    processing: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirmPreMobilisation: () => void;
}): ReactElement {
    const formattedDate = formatDisplayDate(plannedArrivalDate);

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="max-w-lg glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Future Arrival Date Selected
                    </AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="space-y-3 text-sm text-muted-foreground">
                            <p>
                                You selected{' '}
                                <span className="font-medium text-foreground">
                                    {formattedDate}
                                </span>
                                , which is in the future.
                            </p>
                            <p>
                                Already Arrived is only for employees who have
                                arrived today or earlier.
                            </p>
                            <p>
                                Would you like to start this assignment in
                                Pre-Mobilisation instead? The selected date will
                                be saved as the Planned Arrival Date.
                            </p>
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div className="space-y-2 rounded-xl border border-border/60 bg-muted/30 p-3 text-sm dark:border-white/6 dark:bg-white/4">
                    <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                            Assignment status
                        </span>
                        <span className="font-semibold">Active</span>
                    </div>
                    <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                            Starting phase
                        </span>
                        <span className="text-right font-semibold">
                            P0 — Pre-Mobilisation
                        </span>
                    </div>
                    <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                            Planned arrival
                        </span>
                        <span className="text-right font-semibold">
                            {formattedDate}
                        </span>
                    </div>
                    <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                            Actual arrival
                        </span>
                        <span className="text-right font-semibold">
                            Not recorded
                        </span>
                    </div>
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel
                        className="rounded-xl glass-card hover:bg-accent"
                        disabled={processing}
                    >
                        Keep Editing
                    </AlertDialogCancel>
                    <Button
                        type="button"
                        className="rounded-xl"
                        disabled={processing}
                        onClick={onConfirmPreMobilisation}
                    >
                        {processing ? <Spinner className="mr-2" /> : null}
                        Start Pre-Mobilisation
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
