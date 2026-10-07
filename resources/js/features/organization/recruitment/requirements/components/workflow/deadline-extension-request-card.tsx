import { useForm } from '@inertiajs/react';
import { Clock, Loader2 } from 'lucide-react';
import { useState } from 'react';
import RequirementDeadlineExtensionApproveController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementDeadlineExtensionApproveController';
import RequirementDeadlineExtensionRejectController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementDeadlineExtensionRejectController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import type { RequirementDetail } from '@/types/recruitment';

type Props = {
    requirement: RequirementDetail;
};

export function DeadlineExtensionRequestCard({ requirement }: Props) {
    const pending = requirement.pending_deadline_extension;
    const [rejectOpen, setRejectOpen] = useState(false);
    const approveForm = useForm({});
    const rejectForm = useForm({
        decision_note: '',
    });
    const rejectErrors = rejectForm.errors as Record<
        string,
        string | undefined
    >;

    if (!pending || pending.status !== 'pending') {
        return null;
    }

    const canDecide = Boolean(requirement.can_decide_deadline_extension);

    const approve = () => {
        approveForm.post(
            RequirementDeadlineExtensionApproveController.url({
                requirement: requirement.id,
                deadline_extension: pending.id,
            }),
            {
                preserveScroll: true,
                onError: () => {
                    toast.error(
                        'Unable to approve this deadline extension. Please review the current deadline and try again.',
                    );
                },
            },
        );
    };

    const reject = (e: React.FormEvent) => {
        e.preventDefault();
        rejectForm.post(
            RequirementDeadlineExtensionRejectController.url({
                requirement: requirement.id,
                deadline_extension: pending.id,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejectOpen(false);
                    rejectForm.reset();
                },
                onError: () => {
                    toast.error('Unable to reject this deadline extension.');
                },
            },
        );
    };

    return (
        <>
            <Card
                id="deadline-extension-request"
                className="overflow-hidden border-amber-500/40 shadow-xs"
            >
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-base font-semibold">
                        <Clock
                            className="h-4 w-4 text-amber-600 dark:text-amber-400"
                            aria-hidden="true"
                        />
                        {canDecide
                            ? 'Deadline extension requested'
                            : 'Pending extension request'}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3 pt-0 text-sm">
                    <div className="rounded-lg border border-border/70 bg-muted/30 p-3">
                        <p className="text-xs text-muted-foreground">
                            Current deadline
                        </p>
                        <p className="mt-1 font-semibold">
                            {pending.old_deadline_formatted}
                        </p>
                        <p className="mt-3 text-xs text-muted-foreground">
                            Requested deadline
                        </p>
                        <p className="mt-1 font-semibold">
                            {pending.old_deadline_formatted} →{' '}
                            {pending.requested_deadline_formatted}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Requested by
                        </p>
                        <p className="mt-1 font-medium">
                            {pending.requested_by_name ?? 'Recruiter'}
                        </p>
                    </div>
                    {pending.reason ? (
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Reason
                            </p>
                            <p className="mt-1 whitespace-pre-wrap">
                                {pending.reason}
                            </p>
                        </div>
                    ) : null}
                    <p className="text-xs font-medium text-amber-700 dark:text-amber-400">
                        Status: {pending.status_label}
                    </p>
                    {!canDecide ? (
                        <p className="text-xs text-muted-foreground">
                            The official deadline has not changed yet.
                        </p>
                    ) : null}
                    {canDecide ? (
                        <div className="flex flex-wrap gap-2 pt-1">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={
                                    approveForm.processing ||
                                    rejectForm.processing
                                }
                                onClick={() => setRejectOpen(true)}
                            >
                                Reject
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                disabled={
                                    approveForm.processing ||
                                    rejectForm.processing
                                }
                                onClick={approve}
                                className="gap-1.5 bg-emerald-600 text-white hover:bg-emerald-700"
                            >
                                {approveForm.processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : null}
                                Approve
                            </Button>
                        </div>
                    ) : null}
                </CardContent>
            </Card>

            <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
                <DialogContent className="max-w-md p-6">
                    <form onSubmit={reject}>
                        <DialogHeader className="space-y-2">
                            <DialogTitle>Reject deadline extension</DialogTitle>
                            <DialogDescription>
                                The official deadline will remain{' '}
                                {pending.old_deadline_formatted}. A note is
                                optional.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="my-4 space-y-2">
                            <Label
                                htmlFor="decision_note"
                                className="text-xs font-semibold"
                            >
                                Note{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Textarea
                                id="decision_note"
                                rows={3}
                                value={rejectForm.data.decision_note}
                                onChange={(e) =>
                                    rejectForm.setData(
                                        'decision_note',
                                        e.target.value,
                                    )
                                }
                            />
                            {rejectForm.errors.decision_note ? (
                                <p className="text-xs text-rose-500">
                                    {rejectForm.errors.decision_note}
                                </p>
                            ) : null}
                            {rejectErrors.status ? (
                                <p className="text-xs text-rose-500">
                                    {rejectErrors.status}
                                </p>
                            ) : null}
                        </div>
                        <DialogFooter className="flex flex-row justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRejectOpen(false)}
                                disabled={rejectForm.processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={rejectForm.processing}
                                className="gap-1.5"
                            >
                                {rejectForm.processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : null}
                                Reject
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
