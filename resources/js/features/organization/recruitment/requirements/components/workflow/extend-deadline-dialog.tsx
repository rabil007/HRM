import { useForm } from '@inertiajs/react';
import { Calendar, Clock, Loader2 } from 'lucide-react';
import { useEffect } from 'react';
import RequirementExtendDeadlineController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementExtendDeadlineController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
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

export function ExtendDeadlineDialog({
    open,
    onOpenChange,
    requirement,
    onSuccess,
}: Props) {
    const isRequest = requirement?.deadline_extension_mode === 'request';
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            new_required_by_date: '',
            reason: '',
        });
    const formErrors = errors as Record<string, string | undefined>;

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
        post(RequirementExtendDeadlineController.url(requirement.id), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                reset();
                onSuccess?.();
            },
            onError: () => {
                toast.error(
                    isRequest
                        ? 'Failed to request a deadline extension. Please check the fields.'
                        : 'Failed to extend deadline. Please check the fields.',
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
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <Clock className="h-5 w-5" />
                            </div>
                            <div>
                                <DialogTitle className="text-lg font-bold">
                                    {isRequest
                                        ? 'Request Deadline Extension'
                                        : 'Extend Deadline'}
                                </DialogTitle>
                                <DialogDescription className="text-xs text-muted-foreground">
                                    {requirement.requirement_number} —{' '}
                                    {requirement.client_name}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="my-5 space-y-4">
                        <div className="flex items-center justify-between rounded-lg border border-border/70 bg-muted/30 p-3 text-xs">
                            <span className="text-muted-foreground">
                                Current deadline
                            </span>
                            <span className="font-semibold text-foreground">
                                {requirement.required_by_date_formatted || '—'}
                            </span>
                        </div>

                        {isRequest ? (
                            <p className="text-xs text-muted-foreground">
                                The official deadline stays unchanged until the
                                requester approves this request.
                            </p>
                        ) : null}

                        <div className="space-y-2">
                            <Label
                                htmlFor="new_required_by_date"
                                className="text-xs font-semibold"
                            >
                                {isRequest
                                    ? 'Requested deadline'
                                    : 'New deadline'}{' '}
                                <span className="text-rose-500">*</span>
                            </Label>
                            <div className="relative">
                                <Input
                                    id="new_required_by_date"
                                    type="date"
                                    value={data.new_required_by_date}
                                    onChange={(e) =>
                                        setData(
                                            'new_required_by_date',
                                            e.target.value,
                                        )
                                    }
                                    className="pr-10"
                                    required
                                />
                                <Calendar className="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            </div>
                            {errors.new_required_by_date && (
                                <p className="text-xs text-rose-500">
                                    {errors.new_required_by_date}
                                </p>
                            )}
                            {formErrors.new_date && (
                                <p className="text-xs text-rose-500">
                                    {formErrors.new_date}
                                </p>
                            )}
                            {formErrors.status && (
                                <p className="text-xs text-rose-500">
                                    {formErrors.status}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="reason"
                                className="text-xs font-semibold"
                            >
                                {isRequest ? (
                                    <>
                                        Reason{' '}
                                        <span className="text-rose-500">*</span>
                                    </>
                                ) : (
                                    <>
                                        Note{' '}
                                        <span className="font-normal text-muted-foreground">
                                            (optional)
                                        </span>
                                    </>
                                )}
                            </Label>
                            <Textarea
                                id="reason"
                                rows={3}
                                placeholder={
                                    isRequest
                                        ? 'Explain why additional time is needed...'
                                        : 'Add an optional note for the recruiter...'
                                }
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                required={isRequest}
                            />
                            {errors.reason && (
                                <p className="text-xs text-rose-500">
                                    {errors.reason}
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
                            className="gap-1.5"
                        >
                            {processing && (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            )}
                            {isRequest
                                ? 'Request Extension'
                                : 'Extend Deadline'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
