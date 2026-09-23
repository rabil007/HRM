import { useForm } from '@inertiajs/react';
import { Ban, Loader2 } from 'lucide-react';
import { useEffect } from 'react';
import RequirementCancelController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementCancelController';
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

export function CancelRequirementDialog({
    open,
    onOpenChange,
    requirement,
    onSuccess,
}: Props) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            cancellation_reason: '',
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

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(RequirementCancelController.url(requirement.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Requirement cancelled.');
                onOpenChange(false);
                reset();
                onSuccess?.();
            },
            onError: () => {
                toast.error(
                    'Failed to cancel requirement. Please provide a valid cancellation reason.',
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
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-500/10 text-rose-500">
                                <Ban className="h-5 w-5" />
                            </div>
                            <div>
                                <DialogTitle className="text-lg font-bold text-rose-600 dark:text-rose-400">
                                    Cancel Requirement
                                </DialogTitle>
                                <DialogDescription className="text-xs text-muted-foreground">
                                    {requirement.requirement_number} —{' '}
                                    {requirement.client_name}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="my-5 space-y-4">
                        <div className="rounded-lg border border-rose-500/20 bg-rose-500/[0.04] p-3 text-xs text-rose-800 dark:text-rose-200">
                            Cancelling this requirement will mark all open
                            position lines as cancelled. This action moves the
                            requirement to the History tab.
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="cancellation_reason"
                                className="text-xs font-semibold"
                            >
                                Cancellation Reason{' '}
                                <span className="text-rose-500">*</span>
                            </Label>
                            <Textarea
                                id="cancellation_reason"
                                rows={3}
                                placeholder="Why is this requirement being cancelled (e.g. client dropped requisition, project postponed)? Minimum 5 characters..."
                                value={data.cancellation_reason}
                                onChange={(e) =>
                                    setData(
                                        'cancellation_reason',
                                        e.target.value,
                                    )
                                }
                                required
                            />
                            {errors.cancellation_reason && (
                                <p className="text-xs text-rose-500">
                                    {errors.cancellation_reason}
                                </p>
                            )}
                        </div>
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
                            variant="destructive"
                            disabled={processing}
                            className="gap-1.5"
                        >
                            {processing && (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            )}
                            Confirm Cancellation
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
