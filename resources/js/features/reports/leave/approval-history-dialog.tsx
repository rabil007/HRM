import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDisplayDateTime } from '@/lib/format-date';
import type { LeaveReportRow } from './types';

export function LeaveReportApprovalHistoryDialog({
    row,
    open,
    onOpenChange,
}: {
    row: LeaveReportRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const chain = row?.approval_chain ?? [];
    const reassignments = row?.reassignments ?? [];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Approval history</DialogTitle>
                    <DialogDescription>
                        Required approval steps and reassignment history for
                        this leave request.
                    </DialogDescription>
                </DialogHeader>

                {chain.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No approval required.
                    </p>
                ) : (
                    <ol className="space-y-4">
                        {chain.map((step) => (
                            <li key={step.sequence} className="text-sm">
                                <p className="font-semibold">
                                    {step.sequence}.{' '}
                                    {step.policy_step_label ?? 'Approval step'}
                                </p>
                                <p>{step.approver_name}</p>
                                <p className="text-muted-foreground">
                                    {step.status_label}
                                    {step.acted_at
                                        ? ` · ${formatDisplayDateTime(step.acted_at)}`
                                        : ''}
                                </p>
                            </li>
                        ))}
                    </ol>
                )}

                {reassignments.length > 0 ? (
                    <div className="mt-6 space-y-3 border-t border-border/60 pt-4">
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Reassignments
                        </p>
                        {reassignments.map((item, index) => (
                            <div
                                key={`${item.sequence}-${index}`}
                                className="text-sm"
                            >
                                <p className="font-medium">
                                    {item.policy_step_label ??
                                        `Step ${item.sequence}`}
                                </p>
                                <p>Originally assigned: {item.from_name}</p>
                                <p>Reassigned to: {item.to_name}</p>
                                <p>
                                    Reassigned by: {item.reassigned_by ?? '—'}
                                </p>
                                <p>
                                    Date:{' '}
                                    {item.reassigned_at
                                        ? formatDisplayDateTime(
                                              item.reassigned_at,
                                          )
                                        : '—'}
                                </p>
                                {item.reason ? (
                                    <p className="text-muted-foreground">
                                        Reason: {item.reason}
                                    </p>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ) : null}

                <div className="mt-4 flex justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
