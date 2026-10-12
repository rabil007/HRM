import { router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
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
import { cn } from '@/lib/utils';
import { RequiredIndicator } from '@/pages/organization/_components/record-form-field';
import { buildRecruiterNextAction } from '../lib/candidate-recruiter-next-action';
import type { CandidateDetail, CandidateMoveDestination } from '../types';
import type { StageMoveIntent } from './candidate-stage-move-dialog';

type ReasonAction = 'reject' | 'reopen' | null;

export function CandidateRecruiterNextActionPanel({
    candidate,
    onRequestMove,
    className,
}: {
    candidate: CandidateDetail;
    onRequestMove?: (intent: StageMoveIntent) => void;
    className?: string;
}) {
    const model = buildRecruiterNextAction(candidate);
    const destinations = candidate.move_destinations ?? [];
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

    const runRecommended = () => {
        const recommended = model.recommended;

        if (!recommended) {
            return;
        }

        if (recommended.kind === 'link') {
            router.visit(recommended.href);

            return;
        }

        if (recommended.kind === 'post') {
            post(recommended.url);

            return;
        }

        onRequestMove?.({
            candidate,
            destination: recommended.destination,
        });
    };

    const runOther = (key: string) => {
        const action = model.otherActions.find((item) => item.key === key);

        if (!action) {
            return;
        }

        if (action.key === 'reject') {
            setErrors({});
            setReasonAction('reject');

            return;
        }

        if (action.key === 'reopen') {
            setErrors({});
            setReasonAction('reopen');

            return;
        }

        if (action.kind === 'link' && action.href) {
            router.visit(action.href);

            return;
        }

        if (action.kind === 'post' && action.url) {
            post(action.url);

            return;
        }

        if (action.kind === 'destination') {
            const destination = destinations.find(
                (item): item is CandidateMoveDestination =>
                    item.stage === action.destinationStage &&
                    item.kind === action.destinationKind,
            );

            if (destination) {
                onRequestMove?.({ candidate, destination });
            }
        }
    };

    return (
        <>
            <section
                className={cn(
                    'rounded-xl border border-primary/20 bg-card p-4 shadow-xs',
                    className,
                )}
                aria-labelledby="recruiter-next-action-heading"
            >
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id="recruiter-next-action-heading"
                        className="text-sm font-semibold tracking-wide uppercase"
                    >
                        Recruiter Next Action
                    </h2>
                    <Badge variant="outline" className="font-normal">
                        {model.currentState}
                    </Badge>
                </div>

                <div className="space-y-3">
                    <div>
                        <p className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                            Recommended next step
                        </p>
                        {model.recommended ? (
                            <Button
                                className="mt-1.5 w-full justify-between"
                                onClick={runRecommended}
                                disabled={processing}
                            >
                                <span>{model.recommended.label}</span>
                                <ArrowRight className="h-4 w-4 opacity-80" />
                            </Button>
                        ) : (
                            <p className="mt-1.5 text-sm text-muted-foreground">
                                No forward action is available right now.
                            </p>
                        )}
                    </div>

                    {model.why ? (
                        <div>
                            <p className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                                Why this action
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {model.why}
                            </p>
                        </div>
                    ) : null}

                    {model.otherActions.length > 0 ? (
                        <div>
                            <p className="mb-1.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                                Other valid actions
                            </p>
                            <div className="flex flex-wrap gap-1.5">
                                {model.otherActions.map((action) => (
                                    <Button
                                        key={action.key}
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="h-8 text-xs"
                                        disabled={processing}
                                        onClick={() => runOther(action.key)}
                                    >
                                        {action.label}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    ) : null}

                    {model.attention.length > 0 ? (
                        <div className="rounded-lg border border-amber-500/30 bg-amber-50/60 p-2.5 dark:bg-amber-950/30">
                            <p className="mb-1.5 flex items-center gap-1.5 text-[11px] font-medium tracking-wide text-amber-900 uppercase dark:text-amber-200">
                                <AlertTriangle className="h-3.5 w-3.5" />
                                Needs attention
                            </p>
                            <ul className="space-y-1 text-xs text-amber-950 dark:text-amber-100">
                                {model.attention.map((item) => (
                                    <li key={item}>{item}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                </div>
            </section>

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
                        <Label htmlFor="next-action-reason">
                            Reason
                            <RequiredIndicator show />
                        </Label>
                        <Textarea
                            id="next-action-reason"
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
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant={
                                reasonAction === 'reject'
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={processing || reason.trim().length < 3}
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
