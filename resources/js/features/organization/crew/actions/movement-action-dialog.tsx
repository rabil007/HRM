import { useForm } from '@inertiajs/react';
import type { ReactElement, RefObject } from 'react';
import { useEffect, useRef } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    defaultDestinationTourSignoffChoice,
    findRankTourOption,
    normalizeTourSignoffPayload,
} from '@/features/organization/crew/lib/tour-signoff';
import { cn } from '@/lib/utils';
import { performAction } from '@/routes/organization/crew-assignments';
import type {
    CrewAssignmentFormOptions,
    CrewMovementAction,
    CrewMovementActionFormData,
    CrewMovementContext,
} from '../types';
import { ApproveMobilisationForm } from './forms/approve-mobilisation-form';
import { CancelAssignmentForm } from './forms/cancel-assignment-form';
import { CloseAssignmentForm } from './forms/close-assignment-form';
import { CompleteTrainingForm } from './forms/complete-training-form';
import { ConfirmDisembarkationForm } from './forms/confirm-disembarkation-form';
import { JoinVesselForm } from './forms/join-vessel-form';
import { MarkReadyForm } from './forms/mark-ready-form';
import { PlanSignoffForm } from './forms/plan-signoff-form';
import { RecordArrivalForm } from './forms/record-arrival-form';
import { RedeployForm } from './forms/redeploy-form';
import { SendToTrainingForm } from './forms/send-to-training-form';
import { TransferVesselForm } from './forms/transfer-vessel-form';
import { TravelHomeForm } from './forms/travel-home-form';
import { getMovementActionConfig } from './movement-action-config';
import { MovementContextCard } from './movement-context-card';
import { MovementImpactCard } from './movement-impact-card';

function defaultDateTimeLocal(): string {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());

    return now.toISOString().slice(0, 16);
}

function resolveJoinSignoffChoice(
    context: CrewMovementContext,
    formOptions?: CrewAssignmentFormOptions,
): CrewMovementActionFormData['planned_signoff_choice'] {
    if (context.planned_signoff_at) {
        return 'existing_plan';
    }

    return defaultDestinationTourSignoffChoice(
        findRankTourOption(formOptions?.ranks, context.rank_id),
    );
}

function resolveInitialSignoffChoice(
    action: CrewMovementAction,
    context: CrewMovementContext,
    formOptions?: CrewAssignmentFormOptions,
): CrewMovementActionFormData['planned_signoff_choice'] {
    if (action === 'join_vessel') {
        return resolveJoinSignoffChoice(context, formOptions);
    }

    if (action === 'transfer_vessel') {
        return defaultDestinationTourSignoffChoice(
            findRankTourOption(formOptions?.ranks, context.rank_id),
        );
    }

    // Redeploy opens on P0 — Tour fields are excluded until starting_phase = p4.
    return 'manual_override';
}

function buildInitialForm(
    action: CrewMovementAction,
    context: CrewMovementContext,
    formOptions?: CrewAssignmentFormOptions,
): CrewMovementActionFormData {
    const config = getMovementActionConfig(action);
    const nextPhase = config.nextPhaseOptions?.[0]?.value ?? '';

    return {
        action,
        occurred_at: defaultDateTimeLocal(),
        next_phase: nextPhase,
        starting_phase: action === 'redeploy' ? 'p0' : '',
        provider: '',
        course: '',
        planned_start_at: '',
        planned_end_at: '',
        remarks: '',
        vessel_id: action === 'transfer_vessel' ? null : context.vessel_id,
        rank_id: context.rank_id,
        client_id: context.client_id,
        company_visa_type_id: context.visa_type_id,
        planned_signoff_at:
            action === 'redeploy' || action === 'transfer_vessel'
                ? ''
                : (context.planned_signoff_at ?? ''),
        planned_travel_at: '',
        reason: '',
        planned_signoff_choice: resolveInitialSignoffChoice(
            action,
            context,
            formOptions,
        ),
        planned_signoff_override_reason: '',
    };
}

function ActionForm({
    action,
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
}: {
    action: CrewMovementAction;
    form: ReturnType<typeof useForm<CrewMovementActionFormData>>;
    config: ReturnType<typeof getMovementActionConfig>;
    context: CrewMovementContext;
    formOptions?: CrewAssignmentFormOptions;
    firstFieldRef: RefObject<HTMLInputElement | HTMLTextAreaElement | null>;
}): ReactElement | null {
    const props = { form, config, context, formOptions, firstFieldRef };

    switch (action) {
        case 'approve_mobilisation':
            return <ApproveMobilisationForm {...props} />;
        case 'record_arrival':
            return <RecordArrivalForm {...props} />;
        case 'send_to_training':
            return <SendToTrainingForm {...props} />;
        case 'complete_training':
            return <CompleteTrainingForm {...props} />;
        case 'mark_ready':
            return <MarkReadyForm {...props} />;
        case 'join_vessel':
            return <JoinVesselForm {...props} />;
        case 'plan_signoff':
            return <PlanSignoffForm {...props} />;
        case 'confirm_disembarkation':
            return <ConfirmDisembarkationForm {...props} />;
        case 'travel_home':
            return <TravelHomeForm {...props} />;
        case 'close_assignment':
            return <CloseAssignmentForm {...props} />;
        case 'cancel_assignment':
            return <CancelAssignmentForm {...props} />;
        case 'transfer_vessel':
            return <TransferVesselForm {...props} />;
        case 'redeploy':
            return <RedeployForm {...props} />;
        default:
            return null;
    }
}

export function MovementActionDialog({
    open,
    onOpenChange,
    action,
    assignmentId,
    movementContext,
    formOptions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    action: CrewMovementAction | null;
    assignmentId: number;
    movementContext: CrewMovementContext;
    formOptions?: CrewAssignmentFormOptions;
}): ReactElement {
    const firstFieldRef = useRef<HTMLInputElement | HTMLTextAreaElement | null>(
        null,
    );
    const form = useForm<CrewMovementActionFormData>(
        buildInitialForm(
            action ?? 'approve_mobilisation',
            movementContext,
            formOptions,
        ),
    );

    useEffect(() => {
        if (!open || !action) {
            return;
        }

        form.clearErrors();
        form.setData(buildInitialForm(action, movementContext, formOptions));
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when dialog opens for an action
    }, [open, action, movementContext.assignment_id]);

    useEffect(() => {
        if (!open || !action) {
            return;
        }

        const timer = window.setTimeout(() => {
            firstFieldRef.current?.focus();
        }, 50);

        return () => window.clearTimeout(timer);
    }, [open, action]);

    const handleOpenChange = (nextOpen: boolean): void => {
        onOpenChange(nextOpen);
    };

    const submit = (): void => {
        if (!action) {
            return;
        }

        form.transform((data) => {
            let payload = { ...data };

            if (action === 'send_to_training') {
                payload.planned_start_at = data.occurred_at;
            }

            if (action === 'travel_home') {
                delete (payload as { planned_travel_at?: string })
                    .planned_travel_at;
            }

            payload = normalizeTourSignoffPayload(
                payload,
                action,
            ) as CrewMovementActionFormData;

            return payload;
        });

        form.post(performAction.url(assignmentId), {
            preserveScroll: true,
            onFinish: () => {
                form.transform((data) => data);
            },
            onSuccess: () => {
                onOpenChange(false);
            },
        });
    };

    if (!action) {
        return (
            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent className="sm:max-w-lg" />
            </Dialog>
        );
    }

    const config = getMovementActionConfig(action);
    const isDestructive = Boolean(config.destructive);
    const isLarge =
        action === 'join_vessel' ||
        action === 'confirm_disembarkation' ||
        action === 'transfer_vessel' ||
        action === 'redeploy';
    const cancelLabel = config.keepOpenLabel ?? 'Cancel';

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent
                className={cn(
                    'flex max-h-[90vh] flex-col gap-0 overflow-hidden glass-card p-0',
                    isLarge ? 'sm:max-w-2xl' : 'sm:max-w-lg',
                )}
            >
                <DialogHeader className="shrink-0 space-y-1.5 border-b border-border/60 px-6 py-4 text-left">
                    <DialogTitle>{config.title}</DialogTitle>
                    <DialogDescription>{config.description}</DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                    <MovementContextCard context={movementContext} />

                    <ActionForm
                        action={action}
                        form={form}
                        config={config}
                        context={movementContext}
                        formOptions={formOptions}
                        firstFieldRef={firstFieldRef}
                    />

                    <MovementImpactCard
                        title={config.impactTitle}
                        description={config.impactDescription}
                        destructive={isDestructive}
                    />

                    <InputError
                        message={
                            'error' in form.errors
                                ? String(form.errors.error ?? '')
                                : undefined
                        }
                    />
                    <InputError message={form.errors.action} />
                </div>

                <DialogFooter className="shrink-0 border-t border-border/60 px-6 py-4 sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={form.processing}
                    >
                        {cancelLabel}
                    </Button>
                    <Button
                        type="button"
                        variant={isDestructive ? 'destructive' : 'default'}
                        onClick={submit}
                        disabled={form.processing}
                    >
                        {form.processing ? <Spinner className="mr-2" /> : null}
                        {config.submitLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
