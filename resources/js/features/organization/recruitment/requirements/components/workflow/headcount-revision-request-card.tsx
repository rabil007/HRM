import { useForm } from '@inertiajs/react';
import { Loader2, Users } from 'lucide-react';
import { useState } from 'react';
import RequirementHeadcountRevisionApproveController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementHeadcountRevisionApproveController';
import RequirementHeadcountRevisionRejectController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementHeadcountRevisionRejectController';
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

export function HeadcountRevisionRequestCard({ requirement }: Props) {
    const pending = requirement.pending_headcount_revision;
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

    const canDecide = Boolean(requirement.can_decide_headcount_revision);

    const approve = () => {
        approveForm.post(
            RequirementHeadcountRevisionApproveController.url({
                requirement: requirement.id,
                headcount_revision: pending.id,
            }),
            {
                preserveScroll: true,
                onError: () => {
                    toast.error(
                        'Unable to approve this headcount revision. Please review the current headcount and try again.',
                    );
                },
            },
        );
    };

    const reject = (event: React.FormEvent) => {
        event.preventDefault();
        rejectForm.post(
            RequirementHeadcountRevisionRejectController.url({
                requirement: requirement.id,
                headcount_revision: pending.id,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejectOpen(false);
                    rejectForm.reset();
                },
                onError: () => {
                    toast.error('Unable to reject this headcount revision.');
                },
            },
        );
    };

    return (
        <>
            <Card
                id="headcount-revision-request"
                className="overflow-hidden border-amber-500/40 shadow-xs"
            >
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-base font-semibold">
                        <Users
                            className="h-4 w-4 text-amber-600 dark:text-amber-400"
                            aria-hidden="true"
                        />
                        Headcount Revision Requested
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3 pt-0 text-sm">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Requested by
                        </p>
                        <p className="mt-1 font-medium">
                            {pending.requested_by_name ?? 'User'} —{' '}
                            {pending.initiator_label}
                        </p>
                    </div>
                    <div className="space-y-2">
                        <p className="text-xs text-muted-foreground">Changes</p>
                        {pending.lines.map((line) => (
                            <div
                                key={line.id}
                                className="rounded-lg border border-border/70 bg-muted/30 p-3"
                            >
                                <p className="font-semibold">
                                    {line.position_title}
                                </p>
                                <p className="mt-1">
                                    {line.old_headcount} →{' '}
                                    {line.requested_headcount}
                                </p>
                            </div>
                        ))}
                    </div>
                    {pending.reason ? (
                        <div>
                            <p className="text-xs text-muted-foreground">
                                {pending.note_label}
                            </p>
                            <p className="mt-1 whitespace-pre-wrap">
                                {pending.reason}
                            </p>
                        </div>
                    ) : null}
                    <p className="text-xs font-medium text-amber-700 dark:text-amber-400">
                        Status: {pending.status_label}
                    </p>
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
                            <DialogTitle>Reject headcount revision</DialogTitle>
                            <DialogDescription>
                                The official headcount will remain unchanged. A
                                note is optional.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="my-4 space-y-2">
                            <Label
                                htmlFor="headcount-decision-note"
                                className="text-xs font-semibold"
                            >
                                Note{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Textarea
                                id="headcount-decision-note"
                                rows={3}
                                value={rejectForm.data.decision_note}
                                onChange={(event) =>
                                    rejectForm.setData(
                                        'decision_note',
                                        event.target.value,
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
