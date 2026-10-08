import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { actions } from '@/lib/design-system';
import type { HireDateChangePreview } from '@/pages/organization/_lib/hire-date-change-preview';

function formatHireDate(value: string | null): string {
    if (!value) {
        return 'Not set';
    }

    return value;
}

export function HireDateChangeWarningDialog({
    preview,
    open,
    onOpenChange,
    onConfirm,
    processing,
}: {
    preview: HireDateChangePreview | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    processing?: boolean;
}) {
    const [acknowledged, setAcknowledged] = useState(false);

    if (!preview) {
        return null;
    }

    const years = preview.annual_balance_years ?? [];

    return (
        <AlertDialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (!nextOpen) {
                    setAcknowledged(false);
                }

                onOpenChange(nextOpen);
            }}
        >
            <AlertDialogContent className="max-w-lg glass-card">
                <AlertDialogHeader>
                    <div className="mb-1 flex items-center gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-amber-400">
                            <AlertTriangle className="size-4" />
                        </span>
                        <AlertDialogTitle>
                            Hire Date Change — Annual Leave Allocation Warning
                        </AlertDialogTitle>
                    </div>
                    <AlertDialogDescription asChild>
                        <div className="space-y-3 text-sm text-muted-foreground">
                            <p>
                                The employee already has Annual Leave
                                allocations.
                            </p>
                            <p>
                                Changing the Date of Hire may affect the
                                calculated annual leave entitlement.
                            </p>
                            <p>
                                Existing annual leave balances, including
                                manually adjusted values, will{' '}
                                <span className="font-medium text-foreground">
                                    not
                                </span>{' '}
                                be automatically recalculated or modified.
                            </p>
                            <p>
                                The updated hire date will be used for future
                                new allocations. Please review any existing
                                allocations manually if necessary.
                            </p>
                            <ul className="list-disc space-y-1 pl-5">
                                <li>
                                    Previous Date of Hire:{' '}
                                    <span className="font-medium text-foreground">
                                        {formatHireDate(
                                            preview.previous_hire_date,
                                        )}
                                    </span>
                                </li>
                                <li>
                                    New Date of Hire:{' '}
                                    <span className="font-medium text-foreground">
                                        {formatHireDate(preview.new_hire_date)}
                                    </span>
                                </li>
                                {years.length > 0 && (
                                    <li>
                                        Years with existing Annual Leave
                                        allocations:{' '}
                                        <span className="font-medium text-foreground">
                                            {years.join(', ')}
                                        </span>
                                    </li>
                                )}
                            </ul>
                            <div className="flex items-start gap-2 rounded-lg border border-border/60 bg-muted/30 p-3">
                                <Checkbox
                                    id="hire-date-change-ack"
                                    checked={acknowledged}
                                    onCheckedChange={(checked) =>
                                        setAcknowledged(checked === true)
                                    }
                                />
                                <Label
                                    htmlFor="hire-date-change-ack"
                                    className="cursor-pointer text-sm leading-snug font-normal text-foreground"
                                >
                                    I understand that existing leave allocations
                                    will not be automatically recalculated.
                                </Label>
                            </div>
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter className="flex-col gap-2 sm:flex-row">
                    <AlertDialogCancel
                        className={actions.dialogSecondary}
                        disabled={processing}
                    >
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className={actions.dialogPrimary}
                        disabled={!acknowledged || processing}
                        onClick={(event) => {
                            event.preventDefault();
                            onConfirm();
                        }}
                    >
                        Confirm &amp; Save
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
