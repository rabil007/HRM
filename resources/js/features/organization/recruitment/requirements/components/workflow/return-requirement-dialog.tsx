import { useForm } from '@inertiajs/react';
import { Loader2, Undo2 } from 'lucide-react';
import { useEffect } from 'react';
import RequirementReturnController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementReturnController';
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

export function ReturnRequirementDialog({
    open,
    onOpenChange,
    requirement,
    onSuccess,
}: Props) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            return_reason: '',
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
        post(RequirementReturnController.url(requirement.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Requirement returned to the requester.');
                onOpenChange(false);
                reset();
                onSuccess?.();
            },
            onError: () => {
                toast.error(
                    'Failed to return requirement. Please provide a valid return reason.',
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
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-orange-500/10 text-orange-600 dark:text-orange-400">
                                <Undo2 className="h-5 w-5" />
                            </div>
                            <div>
                                <DialogTitle className="text-lg font-bold text-orange-700 dark:text-orange-400">
                                    Return requirement
                                </DialogTitle>
                                <DialogDescription className="text-xs text-muted-foreground">
                                    {requirement.requirement_number} —{' '}
                                    {requirement.client_name}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="my-5 space-y-4">
                        <div className="rounded-lg border border-orange-500/20 bg-orange-500/[0.04] p-3 text-xs text-orange-900 dark:text-orange-200">
                            The requirement moves back to{' '}
                            <strong>Returned</strong> so the requester can
                            revise and resubmit. Active recruitment does not
                            start until it is approved again.
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="return_reason"
                                className="text-xs font-semibold"
                            >
                                Return reason{' '}
                                <span className="text-rose-500">*</span>
                            </Label>
                            <Textarea
                                id="return_reason"
                                rows={3}
                                placeholder="Explain what must be corrected before approval (minimum 5 characters)..."
                                value={data.return_reason}
                                onChange={(e) =>
                                    setData('return_reason', e.target.value)
                                }
                                required
                            />
                            {errors.return_reason && (
                                <p className="text-xs text-rose-500">
                                    {errors.return_reason}
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
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="gap-1.5 bg-orange-600 text-white hover:bg-orange-700"
                        >
                            {processing && (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            )}
                            Return requirement
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
