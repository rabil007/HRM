import { router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { CandidateIndexRow } from '../types';

type ReasonAction = 'reject' | 'reopen' | null;

type PrimaryAction =
    | {
          kind: 'post';
          label: string;
          url: string;
      }
    | {
          kind: 'link';
          label: string;
          href: string;
      };

function resolvePrimaryAction(
    candidate: CandidateIndexRow,
): PrimaryAction | null {
    if (candidate.can_move_forward) {
        return {
            kind: 'post',
            label:
                candidate.stage === 'applied'
                    ? 'Move to Screening'
                    : 'Move to Interview',
            url: `/organization/recruitment/candidates/${candidate.id}/move`,
        };
    }

    if (candidate.can_select) {
        return {
            kind: 'post',
            label: 'Mark Selected',
            url: `/organization/recruitment/candidates/${candidate.id}/select`,
        };
    }

    if (candidate.can_prepare_offer) {
        return {
            kind: 'link',
            label: 'Prepare Offer',
            href: `/organization/recruitment/candidates/${candidate.id}#offer-jol`,
        };
    }

    if (candidate.can_confirm_joined) {
        return {
            kind: 'link',
            label: 'Confirm Joined',
            href: `/organization/recruitment/candidates/${candidate.id}#offer-jol`,
        };
    }

    return null;
}

function blockedNextStepHint(candidate: CandidateIndexRow): string | null {
    if (
        candidate.can_move_forward ||
        candidate.can_select ||
        candidate.can_prepare_offer ||
        candidate.can_confirm_joined ||
        candidate.can_reject ||
        candidate.can_undo_selected ||
        candidate.can_reopen
    ) {
        return null;
    }

    if (!candidate.parents_valid) {
        return 'Requirement or position line is closed — reopen parents before moving.';
    }

    if (!candidate.can_move) {
        return 'You need move permission and ownership of this requirement.';
    }

    if (candidate.stage === 'offer_jol') {
        return 'Complete offer acceptance and joining readiness on the candidate page.';
    }

    if (candidate.stage === 'joined') {
        return 'No further stage moves — convert on the candidate page if needed.';
    }

    if (candidate.stage === 'rejected') {
        return 'Reopen requires manage permission.';
    }

    return 'No move actions available for this candidate.';
}

export function CandidateMovementActions({
    candidate,
    density = 'comfortable',
}: {
    candidate: CandidateIndexRow;
    density?: 'comfortable' | 'compact';
}) {
    const [reasonAction, setReasonAction] = useState<ReasonAction>(null);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const post = (url: string, data: Record<string, unknown> = {}) => {
        setProcessing(true);
        setErrors({});
        router.post(
            url,
            {
                lock_version: candidate.lock_version,
                expected_stage: candidate.stage,
                expected_outcome: candidate.interview_outcome ?? '',
                ...data,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setReasonAction(null);
                    setReason('');
                    setErrors({});
                },
                onError: (pageErrors) => {
                    setErrors(pageErrors as Record<string, string>);
                },
                onFinish: () => {
                    setProcessing(false);
                },
            },
        );
    };

    const generalError =
        errors.candidate ||
        errors.lock_version ||
        errors.stage ||
        errors.interview_outcome ||
        errors.reason;

    const primary = resolvePrimaryAction(candidate);
    const blockedHint = blockedNextStepHint(candidate);
    const isCompact = density === 'compact';

    const overflowItems: Array<{
        key: string;
        label: string;
        destructive?: boolean;
        onSelect: () => void;
    }> = [];

    if (candidate.can_undo_selected) {
        overflowItems.push({
            key: 'undo',
            label: 'Undo Selected',
            onSelect: () =>
                post(
                    `/organization/recruitment/candidates/${candidate.id}/undo-select`,
                ),
        });
    }

    if (candidate.can_reject) {
        overflowItems.push({
            key: 'reject',
            label: 'Reject',
            destructive: true,
            onSelect: () => {
                setErrors({});
                setReasonAction('reject');
            },
        });
    }

    if (candidate.can_reopen) {
        overflowItems.push({
            key: 'reopen',
            label: 'Reopen',
            onSelect: () => {
                setErrors({});
                setReasonAction('reopen');
            },
        });
    }

    return (
        <>
            {isCompact ? (
                <div className="flex w-full items-center gap-1.5">
                    {primary ? (
                        primary.kind === 'link' ? (
                            <Button
                                size="sm"
                                className="h-8 flex-1 text-xs"
                                asChild
                            >
                                <a href={primary.href}>{primary.label}</a>
                            </Button>
                        ) : (
                            <Button
                                size="sm"
                                className="h-8 flex-1 text-xs"
                                disabled={processing}
                                onClick={() => post(primary.url)}
                            >
                                {primary.label}
                            </Button>
                        )
                    ) : blockedHint ? (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span className="flex-1 truncate text-xs text-muted-foreground">
                                    Next step blocked
                                </span>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-xs">
                                {blockedHint}
                            </TooltipContent>
                        </Tooltip>
                    ) : (
                        <span className="flex-1" />
                    )}

                    {overflowItems.length > 0 ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="h-8 w-8 shrink-0 px-0"
                                    disabled={processing}
                                    aria-label="More actions"
                                >
                                    <MoreHorizontal className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {overflowItems.map((item) => (
                                    <DropdownMenuItem
                                        key={item.key}
                                        variant={
                                            item.destructive
                                                ? 'destructive'
                                                : 'default'
                                        }
                                        onSelect={item.onSelect}
                                    >
                                        {item.label}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null}
                </div>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {primary ? (
                        primary.kind === 'link' ? (
                            <Button size="sm" variant="outline" asChild>
                                <a href={primary.href}>{primary.label}</a>
                            </Button>
                        ) : (
                            <Button
                                size="sm"
                                variant={
                                    primary.label === 'Mark Selected'
                                        ? 'default'
                                        : 'outline'
                                }
                                disabled={processing}
                                onClick={() => post(primary.url)}
                            >
                                {primary.label}
                            </Button>
                        )
                    ) : null}
                    {candidate.can_undo_selected ? (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={processing}
                            onClick={() =>
                                post(
                                    `/organization/recruitment/candidates/${candidate.id}/undo-select`,
                                )
                            }
                        >
                            Undo Selected
                        </Button>
                    ) : null}
                    {candidate.can_reject ? (
                        <Button
                            size="sm"
                            variant="destructive"
                            disabled={processing}
                            onClick={() => {
                                setErrors({});
                                setReasonAction('reject');
                            }}
                        >
                            Reject
                        </Button>
                    ) : null}
                    {candidate.can_reopen ? (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={processing}
                            onClick={() => {
                                setErrors({});
                                setReasonAction('reopen');
                            }}
                        >
                            Reopen
                        </Button>
                    ) : null}
                    {!primary && blockedHint ? (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span className="text-xs text-muted-foreground">
                                    Next step blocked
                                </span>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-xs">
                                {blockedHint}
                            </TooltipContent>
                        </Tooltip>
                    ) : null}
                </div>
            )}

            {reasonAction === null && generalError ? (
                <p
                    className={cn(
                        'text-xs text-destructive',
                        isCompact ? 'mt-1' : 'mt-2',
                    )}
                >
                    {generalError}
                </p>
            ) : null}

            <Dialog
                open={reasonAction !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setReasonAction(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {reasonAction === 'reopen'
                                ? 'Reopen rejected candidate'
                                : 'Reject candidate'}
                        </DialogTitle>
                        <DialogDescription>
                            {reasonAction === 'reopen'
                                ? 'Provide a reason. The candidate returns to the pre-rejection stage.'
                                : 'A reason is required and will be stored in movement history.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label>Reason</Label>
                        <Textarea
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                        />
                        {errors.reason ? (
                            <p className="text-xs text-destructive">
                                {errors.reason}
                            </p>
                        ) : null}
                        {errors.lock_version ||
                        errors.candidate ||
                        errors.stage ||
                        errors.interview_outcome ? (
                            <p className="text-xs text-destructive">
                                {errors.lock_version ||
                                    errors.candidate ||
                                    errors.stage ||
                                    errors.interview_outcome}
                            </p>
                        ) : null}
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setReasonAction(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant={
                                reasonAction === 'reject'
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={processing || reason.trim() === ''}
                            onClick={() => {
                                if (reasonAction === 'reject') {
                                    post(
                                        `/organization/recruitment/candidates/${candidate.id}/reject`,
                                        { reason },
                                    );
                                } else if (reasonAction === 'reopen') {
                                    post(
                                        `/organization/recruitment/candidates/${candidate.id}/reopen`,
                                        { reason },
                                    );
                                }
                            }}
                        >
                            Confirm
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
