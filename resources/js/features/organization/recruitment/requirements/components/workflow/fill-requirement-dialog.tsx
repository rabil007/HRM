import { useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Loader2 } from 'lucide-react';
import { useEffect } from 'react';
import RequirementFillController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementFillController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/lib/toast';
import type {
    RequirementDetail,
    RequirementIndexRow,
} from '@/types/recruitment';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    requirement: RequirementIndexRow | RequirementDetail | null;
    onSuccess?: () => void;
};

export function FillRequirementDialog({
    open,
    onOpenChange,
    requirement,
    onSuccess,
}: Props) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm<{
            reason: string;
            status?: string;
        }>({
            reason: '',
        });

    useEffect(() => {
        if (open) {
            clearErrors();
            reset();
        }
    }, [open, clearErrors, reset]);

    if (!requirement) {
        return null;
    }

    const unfilledVacancies = requirement.unfilled_vacancies ?? 0;
    const isFullyFulfilled =
        requirement.is_fully_fulfilled ?? unfilledVacancies === 0;
    const canOverrideFill = requirement.can_override_fill ?? false;
    const requiresOverride = !isFullyFulfilled;
    const canSubmit =
        !requiresOverride || (canOverrideFill && data.reason.trim().length > 0);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(RequirementFillController.url(requirement.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    `${requirement.requirement_number} marked as filled & completed.`,
                );
                onOpenChange(false);
                reset();
                onSuccess?.();
            },
            onError: () => {
                toast.error(
                    'Failed to mark requirement as filled. Please check requirements and errors.',
                );
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md p-6">
                <form onSubmit={handleSubmit}>
                    <DialogHeader className="space-y-2">
                        <div className="flex items-center gap-3">
                            <div
                                className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${
                                    requiresOverride
                                        ? 'bg-amber-500/10 text-amber-500'
                                        : 'bg-emerald-500/10 text-emerald-500'
                                }`}
                            >
                                {requiresOverride ? (
                                    <AlertTriangle className="h-5 w-5" />
                                ) : (
                                    <CheckCircle2 className="h-5 w-5" />
                                )}
                            </div>
                            <div>
                                <DialogTitle className="text-lg font-bold">
                                    {requiresOverride
                                        ? 'Partially Fulfilled Closure'
                                        : 'Mark Requirement as Filled'}
                                </DialogTitle>
                                <DialogDescription className="text-xs text-muted-foreground">
                                    {requirement.requirement_number} —{' '}
                                    {requirement.client_name}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="my-5 space-y-4">
                        {requiresOverride ? (
                            <div className="space-y-3">
                                <div className="rounded-lg border border-amber-500/20 bg-amber-500/[0.04] p-3 text-xs text-amber-800 dark:text-amber-200">
                                    <strong className="font-bold">
                                        {requirement.requirement_number}
                                    </strong>{' '}
                                    has{' '}
                                    <span className="font-semibold underline">
                                        {unfilledVacancies} unfilled
                                        vacancy(ies)
                                    </span>
                                    . Marking it as filled without reaching all
                                    targets requires explicit management
                                    override authorization and a mandatory
                                    reason.
                                </div>

                                {!canOverrideFill && (
                                    <div className="rounded-lg border border-rose-500/20 bg-rose-500/[0.04] p-3 text-xs text-rose-800 dark:text-rose-200">
                                        You do not have management override
                                        permission to close a partially
                                        fulfilled requirement.
                                    </div>
                                )}

                                {canOverrideFill && (
                                    <div className="space-y-2">
                                        <Label
                                            htmlFor="fill_override_reason"
                                            className="text-xs font-semibold"
                                        >
                                            Management Override Reason{' '}
                                            <span className="text-rose-500">
                                                *
                                            </span>
                                        </Label>
                                        <Textarea
                                            id="fill_override_reason"
                                            rows={3}
                                            placeholder="Provide reason for marking as filled with unfilled vacancies (e.g. client hiring freeze, budget constraint)..."
                                            value={data.reason}
                                            onChange={(e) =>
                                                setData(
                                                    'reason',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                        {errors.reason && (
                                            <p className="text-xs text-rose-500">
                                                {errors.reason}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/[0.04] p-3 text-xs text-emerald-800 dark:text-emerald-200">
                                All target positions for{' '}
                                <strong className="font-bold">
                                    {requirement.requirement_number}
                                </strong>{' '}
                                have confirmed joined candidates. Marking this
                                requirement as filled will complete it and move
                                it to the History tab.
                            </div>
                        )}
                        {errors.status && (
                            <p className="text-xs text-rose-500">
                                {errors.status}
                            </p>
                        )}
                    </div>

                    <DialogFooter className="flex flex-row justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={processing}
                        >
                            Back
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || !canSubmit}
                        >
                            {processing && (
                                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                            )}
                            {requiresOverride
                                ? 'Confirm Management Override'
                                : 'Mark as Filled'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
