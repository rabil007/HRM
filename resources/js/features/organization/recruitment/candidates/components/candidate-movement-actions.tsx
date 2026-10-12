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
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
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
import { RequiredIndicator } from '@/pages/organization/_components/record-form-field';
import { actionableDestinations } from '../lib/candidate-move-destinations';
import {
    blockedNextStepHint,
    resolvePrimaryAction,
} from '../lib/candidate-primary-action';
import type { CandidateIndexRow, CandidateMoveDestination } from '../types';
import type { StageMoveIntent } from './candidate-stage-move-dialog';

type ReasonAction = 'reject' | 'reopen' | null;

export function CandidateMovementActions({
    candidate,
    density = 'comfortable',
    onRequestMove,
    onRequestSelect,
    onRequestPrepareOffer,
    onRequestReadiness,
    showPrimary = true,
    showSecondaryButtons = true,
}: {
    candidate: CandidateIndexRow;
    density?: 'comfortable' | 'compact';
    onRequestMove?: (intent: StageMoveIntent) => void;
    onRequestSelect?: (candidate: CandidateIndexRow) => void;
    onRequestPrepareOffer?: (candidate: CandidateIndexRow) => void;
    onRequestReadiness?: (candidate: CandidateIndexRow) => void;
    /** When false, hide the primary CTA (e.g. detail page uses Recruiter Next Action). */
    showPrimary?: boolean;
    /** When false, hide Reject / Undo / Reopen outline buttons (keep overflow menu). */
    showSecondaryButtons?: boolean;
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
    const canOpenMoveDialog = typeof onRequestMove === 'function';
    const moveOptions = canOpenMoveDialog
        ? actionableDestinations(candidate)
        : [];

    const requestMove = (destination: CandidateMoveDestination) => {
        if (destination.kind === 'prepare_offer') {
            onRequestPrepareOffer?.(candidate);

            return;
        }

        if (!onRequestMove) {
            return;
        }

        onRequestMove({ candidate, destination });
    };

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

    const runPrimary = () => {
        if (!primary) {
            return;
        }

        if (primary.kind === 'select') {
            onRequestSelect?.(candidate);

            return;
        }

        if (primary.kind === 'prepare_offer') {
            onRequestPrepareOffer?.(candidate);

            return;
        }

        if (primary.kind === 'update_readiness') {
            if (onRequestReadiness) {
                onRequestReadiness(candidate);

                return;
            }

            router.visit(primary.href);

            return;
        }

        if (primary.kind === 'link') {
            router.visit(primary.href);

            return;
        }

        if (primary.kind === 'destination') {
            requestMove(primary.destination);

            return;
        }

        post(primary.url);
    };

    const renderPrimary = () => {
        if (!primary) {
            if (blockedHint) {
                return (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span
                                className={cn(
                                    'text-xs text-muted-foreground',
                                    isCompact && 'flex-1 truncate',
                                )}
                            >
                                Needs follow-up
                            </span>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-xs">
                            {blockedHint}
                        </TooltipContent>
                    </Tooltip>
                );
            }

            return isCompact ? <span className="flex-1" /> : null;
        }

        if (primary.kind === 'destination' && !canOpenMoveDialog) {
            return isCompact ? <span className="flex-1" /> : null;
        }

        if (
            primary.kind === 'select' &&
            typeof onRequestSelect !== 'function'
        ) {
            return isCompact ? <span className="flex-1" /> : null;
        }

        if (
            primary.kind === 'prepare_offer' &&
            typeof onRequestPrepareOffer !== 'function'
        ) {
            return isCompact ? <span className="flex-1" /> : null;
        }

        return (
            <Button
                size="sm"
                className={cn(isCompact && 'h-8 flex-1 text-xs')}
                variant={
                    primary.label === 'Mark Selected' || isCompact
                        ? 'default'
                        : 'outline'
                }
                disabled={processing}
                onClick={runPrimary}
            >
                {primary.label}
            </Button>
        );
    };

    const showOverflow =
        overflowItems.length > 0 ||
        moveOptions.length > 0 ||
        (isCompact && (overflowItems.length > 0 || moveOptions.length > 0));

    return (
        <>
            <div
                className={cn(
                    'flex items-center gap-1.5',
                    isCompact ? 'w-full' : 'flex-wrap',
                )}
            >
                {showPrimary ? renderPrimary() : null}

                {showSecondaryButtons &&
                !isCompact &&
                candidate.can_undo_selected ? (
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

                {showSecondaryButtons && !isCompact && candidate.can_reject ? (
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

                {showSecondaryButtons && !isCompact && candidate.can_reopen ? (
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

                {showOverflow ? (
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
                            {moveOptions.length > 0 ? (
                                <DropdownMenuSub>
                                    <DropdownMenuSubTrigger>
                                        Move to Stage
                                    </DropdownMenuSubTrigger>
                                    <DropdownMenuSubContent>
                                        {moveOptions.map((destination) => (
                                            <DropdownMenuItem
                                                key={`${destination.kind}-${destination.stage}`}
                                                onSelect={() =>
                                                    requestMove(destination)
                                                }
                                            >
                                                {destination.label}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuSubContent>
                                </DropdownMenuSub>
                            ) : null}
                            {moveOptions.length > 0 &&
                            overflowItems.length > 0 ? (
                                <DropdownMenuSeparator />
                            ) : null}
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
                        <Label htmlFor="movement-reason">
                            Reason
                            <RequiredIndicator show />
                        </Label>
                        <Textarea
                            id="movement-reason"
                            value={reason}
                            required
                            aria-required
                            aria-invalid={Boolean(errors.reason)}
                            onChange={(event) => setReason(event.target.value)}
                        />
                        {errors.reason ? (
                            <p className="text-xs text-destructive">
                                {errors.reason}
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
