import { useForm } from '@inertiajs/react';
import { Calendar, Loader2, RotateCcw } from 'lucide-react';
import { useEffect } from 'react';
import RequirementReopenController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementReopenController';
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

export function ReopenRequirementDialog({
    open,
    onOpenChange,
    requirement,
    onSuccess,
}: Props) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            new_required_by_date: '',
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

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(RequirementReopenController.url(requirement.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Requirement reopened successfully.');
                onOpenChange(false);
                reset();
                onSuccess?.();
            },
            onError: () => {
                toast.error(
                    'Failed to reopen requirement. Please check input fields.',
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
                                <RotateCcw className="h-5 w-5" />
                            </div>
                            <div>
                                <DialogTitle className="text-lg font-bold">
                                    Reopen Requirement
                                </DialogTitle>
                                <DialogDescription className="text-xs text-muted-foreground">
                                    {requirement.requirement_number} —{' '}
                                    {requirement.client_name}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="my-5 space-y-4">
                        <div className="rounded-lg border border-border/70 bg-muted/30 p-3 text-xs text-muted-foreground">
                            Reopening will return this requirement to active{' '}
                            <strong className="text-foreground">Open</strong>{' '}
                            status and restore line items so recruitment
                            activities can continue.
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="reopen_new_required_by_date"
                                className="text-xs font-semibold"
                            >
                                New Required-By Target Date{' '}
                                <span className="text-rose-500">*</span>
                            </Label>
                            <div className="relative">
                                <Input
                                    id="reopen_new_required_by_date"
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
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="reopen_reason"
                                className="text-xs font-semibold"
                            >
                                Reason for Reopening{' '}
                                <span className="text-rose-500">*</span>
                            </Label>
                            <Textarea
                                id="reopen_reason"
                                rows={3}
                                placeholder="Explain why this requirement is being reopened..."
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                required
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
                            Confirm Reopen
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
