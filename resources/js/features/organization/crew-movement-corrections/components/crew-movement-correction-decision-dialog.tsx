import { useForm } from '@inertiajs/react';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    approve,
    cancel,
    reject,
} from '@/routes/organization/crew-movement-corrections';

export type CorrectionDecisionMode = 'approve' | 'reject' | 'cancel';

type DecisionConfig = {
    title: string;
    description: string;
    label: string;
    required: boolean;
    submitLabel: string;
    variant: 'default' | 'destructive';
    buildUrl: (id: number) => string;
};

const CONFIG: Record<CorrectionDecisionMode, DecisionConfig> = {
    approve: {
        title: 'Approve correction',
        description:
            'Approving will apply the proposed values to the assignment immediately.',
        label: 'Approval notes (optional)',
        required: false,
        submitLabel: 'Approve & apply',
        variant: 'default',
        buildUrl: (id) => approve.url(id),
    },
    reject: {
        title: 'Reject correction',
        description: 'Provide a reason for rejecting this correction request.',
        label: 'Rejection reason',
        required: true,
        submitLabel: 'Reject request',
        variant: 'destructive',
        buildUrl: (id) => reject.url(id),
    },
    cancel: {
        title: 'Cancel correction',
        description: 'Cancel this pending correction request.',
        label: 'Cancellation notes (optional)',
        required: false,
        submitLabel: 'Cancel request',
        variant: 'destructive',
        buildUrl: (id) => cancel.url(id),
    },
};

export function CrewMovementCorrectionDecisionDialog({
    mode,
    open,
    onOpenChange,
    correctionId,
    onSuccess,
}: {
    mode: CorrectionDecisionMode | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    correctionId: number | null;
    onSuccess?: () => void;
}): ReactElement | null {
    const form = useForm({ decision_notes: '' });

    if (!mode) {
        return null;
    }

    const config = CONFIG[mode];

    const submit = (): void => {
        if (!correctionId) {
            return;
        }

        if (config.required && !form.data.decision_notes.trim()) {
            form.setError('decision_notes', 'This field is required.');

            return;
        }

        form.post(config.buildUrl(correctionId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.clearErrors();
                onOpenChange(false);
                onSuccess?.();
            },
        });
    };

    const handleOpenChange = (nextOpen: boolean): void => {
        if (!nextOpen) {
            form.reset();
            form.clearErrors();
        }

        onOpenChange(nextOpen);
    };

    return (
        <AlertDialog open={open} onOpenChange={handleOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>{config.title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {config.description}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div className="space-y-2">
                    <Label
                        htmlFor="decision_notes"
                        className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                    >
                        {config.label}
                    </Label>
                    <Textarea
                        id="decision_notes"
                        value={form.data.decision_notes}
                        onChange={(event) =>
                            form.setData('decision_notes', event.target.value)
                        }
                        className="min-h-24 rounded-xl border-border bg-card"
                        placeholder="Add context for this decision..."
                    />
                    {form.errors.decision_notes ? (
                        <div className="text-xs font-medium text-destructive">
                            {form.errors.decision_notes}
                        </div>
                    ) : null}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl glass-card hover:bg-accent">
                        Close
                    </AlertDialogCancel>
                    <Button
                        className="rounded-xl"
                        variant={config.variant}
                        onClick={submit}
                        disabled={form.processing}
                    >
                        {config.submitLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
