import { useForm } from '@inertiajs/react';
import { ArrowRightLeft, Loader2 } from 'lucide-react';
import { useEffect } from 'react';
import RequirementTransferOwnershipController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementTransferOwnershipController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import type { RequirementDetail, UserOption } from '@/types/recruitment';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    requirement: RequirementDetail | null;
    requesters: UserOption[];
    recruiters: UserOption[];
    onSuccess?: () => void;
};

export function TransferOwnershipDialog({
    open,
    onOpenChange,
    requirement,
    requesters,
    recruiters,
    onSuccess,
}: Props) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            created_by: '',
            assigned_to: '',
            reason: '',
        });
    const bagErrors = errors as Record<string, string | undefined>;

    useEffect(() => {
        if (open && requirement) {
            clearErrors();
            setData({
                created_by: requirement.created_by
                    ? String(requirement.created_by)
                    : '',
                assigned_to: requirement.assigned_to
                    ? String(requirement.assigned_to)
                    : '',
                reason: '',
            });
        }
    }, [open, requirement, clearErrors, setData]);

    if (!requirement) {
        return null;
    }

    const requiresAssignedRecruiter = [
        'pending_approval',
        'open',
        'on_hold',
    ].includes(requirement.status);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(RequirementTransferOwnershipController.url(requirement.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Ownership transferred.');
                onOpenChange(false);
                reset();
                onSuccess?.();
            },
            onError: () => {
                toast.error(
                    'Failed to transfer ownership. Check the selected users and reason.',
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
                                <ArrowRightLeft className="h-5 w-5" />
                            </div>
                            <div>
                                <DialogTitle className="text-lg font-bold">
                                    Transfer ownership
                                </DialogTitle>
                                <DialogDescription className="text-xs text-muted-foreground">
                                    {requirement.requirement_number} —{' '}
                                    {requirement.client_name}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="my-5 space-y-4">
                        <div className="rounded-lg border border-border/60 bg-muted/20 p-3 text-xs text-muted-foreground">
                            Use this only when the requester or assigned
                            recruiter is inactive or unavailable. Pending
                            deadline and headcount requests stay in place and
                            become actionable by the replacement user.
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="transfer_created_by"
                                className="text-xs font-semibold"
                            >
                                Requester{' '}
                                <span className="text-rose-500">*</span>
                            </Label>
                            <AppSelect
                                value={data.created_by}
                                onValueChange={(value) =>
                                    setData('created_by', value)
                                }
                                placeholder="Select requester"
                            >
                                {requesters.map((user) => (
                                    <AppSelectItem
                                        key={user.id}
                                        value={String(user.id)}
                                    >
                                        {user.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {errors.created_by ? (
                                <p className="text-xs text-rose-500">
                                    {errors.created_by}
                                </p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="transfer_assigned_to"
                                className="text-xs font-semibold"
                            >
                                Assigned recruiter
                                {requiresAssignedRecruiter ? (
                                    <span className="text-rose-500"> *</span>
                                ) : null}
                            </Label>
                            <AppSelect
                                value={data.assigned_to}
                                onValueChange={(value) =>
                                    setData('assigned_to', value)
                                }
                                placeholder="Select recruiter"
                            >
                                {!requiresAssignedRecruiter ? (
                                    <AppSelectItem value="">
                                        Unassigned
                                    </AppSelectItem>
                                ) : null}
                                {recruiters.map((user) => (
                                    <AppSelectItem
                                        key={user.id}
                                        value={String(user.id)}
                                    >
                                        {user.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {errors.assigned_to ? (
                                <p className="text-xs text-rose-500">
                                    {errors.assigned_to}
                                </p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="transfer_reason"
                                className="text-xs font-semibold"
                            >
                                Reason <span className="text-rose-500">*</span>
                            </Label>
                            <Textarea
                                id="transfer_reason"
                                rows={3}
                                placeholder="Why is ownership being transferred (e.g. requester left the company)?"
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                required
                            />
                            {errors.reason ? (
                                <p className="text-xs text-rose-500">
                                    {errors.reason}
                                </p>
                            ) : null}
                            {bagErrors.status ? (
                                <p className="text-xs text-rose-500">
                                    {bagErrors.status}
                                </p>
                            ) : null}
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
                            disabled={processing}
                            className="gap-1.5"
                        >
                            {processing && (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            )}
                            Transfer ownership
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
