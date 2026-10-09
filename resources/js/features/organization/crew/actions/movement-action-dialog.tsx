import { useForm } from '@inertiajs/react';
import type { ReactElement, RefObject } from 'react';
import { Fragment } from 'react';
import { useEffect, useRef, useState } from 'react';
import { ActionImpactPreview } from '@/components/action-impact-preview';
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
import { MovementWorkflowHelp } from '@/features/organization/crew/components/movement-workflow-help';
import {
    SCHEDULE_LATER_HELP,
    TESTING_OVERRIDE_BANNER_MESSAGE,
    isSchedulableMovementAction,
    shouldShowTestingOverrideBanner,
} from '@/features/organization/crew/lib/future-actual-movement-dates';
import { mapMovementErrorMessage } from '@/features/organization/crew/lib/movement-error-message';
import { buildMovementImpactPreview } from '@/features/organization/crew/lib/movement-impact-preview';
import { buildScheduledMovementEditPayload } from '@/features/organization/crew/lib/scheduled-movement-edit-payload';
import {
    defaultDestinationTourSignoffChoice,
    findPositionTourOption,
    normalizeTourSignoffPayload,
} from '@/features/organization/crew/lib/tour-signoff';
import { recommendsVesselTransfer } from '@/features/organization/crew/lib/vessel-transfer-recommendation';
import { nowInCompanyDate, nowInCompanyTime } from '@/lib/company-timezone';
import { cn } from '@/lib/utils';
import { performAction } from '@/routes/organization/crew-assignments';
import { store as storeScheduledMovement } from '@/routes/organization/crew-assignments/scheduled-movements';
import { update as updateScheduledMovement } from '@/routes/organization/crew-scheduled-movements';
import type {
    CrewAssignmentFormOptions,
    CrewMovementAction,
    CrewMovementActionFormData,
    CrewMovementContext,
    CrewScheduledMovementCard,
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
import { VesselTransferRecommendationDialog } from './vessel-transfer-recommendation-dialog';
import type { VesselTransferPrefill } from './vessel-transfer-recommendation-dialog';

function resolveJoinSignoffChoice(
    context: CrewMovementContext,
    formOptions?: CrewAssignmentFormOptions,
): CrewMovementActionFormData['planned_signoff_choice'] {
    if (context.planned_signoff_at) {
        return 'existing_plan';
    }

    return defaultDestinationTourSignoffChoice(
        findPositionTourOption(formOptions?.positions, context.position_id),
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
            findPositionTourOption(formOptions?.positions, context.position_id),
        );
    }

    // Redeploy opens on P0 — Tour fields are excluded until starting_phase = p4.
    return 'manual_override';
}

function applyTransferPrefill(
    data: CrewMovementActionFormData,
    prefill?: VesselTransferPrefill | null,
): CrewMovementActionFormData {
    if (!prefill) {
        return data;
    }

    return {
        ...data,
        vessel_id: prefill.vessel_id ?? data.vessel_id,
        position_id: prefill.position_id ?? data.position_id,
        client_id: prefill.client_id ?? data.client_id,
        occurred_at: prefill.occurred_at || data.occurred_at,
    };
}

function buildInitialForm(
    action: CrewMovementAction,
    context: CrewMovementContext,
    formOptions?: CrewAssignmentFormOptions,
    prefill?: VesselTransferPrefill | null,
): CrewMovementActionFormData {
    const config = getMovementActionConfig(action, context.current_phase_code);
    const nextPhase =
        action === 'record_arrival' && context.current_phase_code === 'p0'
            ? 'p2a'
            : action === 'record_arrival' && context.current_phase_code === 'p1'
              ? 'p2a'
              : action === 'complete_training'
                ? 'p2a'
                : (config.nextPhaseOptions?.[0]?.value ??
                  config.fixedNextPhase ??
                  '');

    const companyTz = context.company_timezone;
    const initialOccurredAt = nowInCompanyTime(companyTz);
    const initialHotelDate = nowInCompanyDate(companyTz);

    const data: CrewMovementActionFormData = {
        action,
        occurred_at: initialOccurredAt,
        next_phase: nextPhase,
        starting_phase: action === 'redeploy' ? 'p0' : '',
        provider:
            action === 'complete_training'
                ? (context.training_provider ?? '')
                : '',
        course:
            action === 'complete_training'
                ? (context.training_course ?? '')
                : '',
        course_id:
            action === 'complete_training'
                ? (context.training_course_id ??
                  formOptions?.courses.find(
                      (c) => c.name === context.training_course,
                  )?.id ??
                  null)
                : null,
        sync_training_to_employee_training: Boolean(
            context.sync_training_enabled,
        ),
        planned_start_at: '',
        planned_end_at: '',
        remarks: '',
        vessel_id: action === 'transfer_vessel' ? null : context.vessel_id,
        position_id: context.position_id,
        client_id: context.client_id,
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
        completion_intent: action === 'travel_home' ? 'close' : '',
        accommodation_status:
            action === 'record_arrival' ||
            action === 'confirm_disembarkation' ||
            action === 'redeploy'
                ? 'hotel'
                : '',
        hotel_id: null,
        room_type_id: null,
        check_in_date:
            action === 'record_arrival' ||
            action === 'confirm_disembarkation' ||
            action === 'redeploy'
                ? initialHotelDate
                : '',
        check_out_date:
            action === 'join_vessel' ||
            action === 'travel_home' ||
            action === 'cancel_assignment'
                ? initialHotelDate
                : '',
        source_check_out_date:
            action === 'redeploy' &&
            context.post_signoff_accommodation?.status === 'open_hotel'
                ? initialHotelDate
                : '',
        no_hotel_accommodation: false,
    };

    return action === 'transfer_vessel'
        ? applyTransferPrefill(data, prefill)
        : data;
}

function ActionForm({
    action,
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
    schedulingMode = false,
}: {
    action: CrewMovementAction;
    form: ReturnType<typeof useForm<CrewMovementActionFormData>>;
    config: ReturnType<typeof getMovementActionConfig>;
    context: CrewMovementContext;
    formOptions?: CrewAssignmentFormOptions;
    firstFieldRef: RefObject<HTMLInputElement | HTMLTextAreaElement | null>;
    schedulingMode?: boolean;
}): ReactElement | null {
    const props = {
        form,
        config,
        context,
        formOptions,
        firstFieldRef,
        schedulingMode,
    };

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

const DEFAULT_SCHEDULABLE_ACTIONS = [
    'approve_mobilisation',
    'record_arrival',
    'send_to_training',
    'complete_training',
    'join_vessel',
    'confirm_disembarkation',
    'travel_home',
    'transfer_vessel',
    'redeploy',
    'close_assignment',
];

function applySchedulePrefill(
    data: CrewMovementActionFormData,
    schedule: CrewScheduledMovementCard | null | undefined,
): CrewMovementActionFormData {
    if (!schedule) {
        return data;
    }

    const payload = schedule.action_payload ?? {};
    const scheduledAt =
        schedule.scheduled_at_input?.replace('T', ' ') ??
        schedule.scheduled_at ??
        data.occurred_at;

    return {
        ...data,
        ...Object.fromEntries(
            Object.entries(payload).filter(
                ([key]) => key !== '_action' && key in data,
            ),
        ),
        occurred_at: scheduledAt.slice(0, 16).replace('T', ' '),
        action: schedule.movement_action as CrewMovementAction,
    };
}

export function MovementActionDialog({
    open,
    onOpenChange,
    action,
    assignmentId,
    movementContext,
    formOptions,
    transferPrefill = null,
    editingSchedule = null,
    canSchedule,
    hasActiveSchedule,
    schedulableActions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    action: CrewMovementAction | null;
    assignmentId: number;
    movementContext: CrewMovementContext;
    formOptions?: CrewAssignmentFormOptions;
    transferPrefill?: VesselTransferPrefill | null;
    editingSchedule?: CrewScheduledMovementCard | null;
    canSchedule?: boolean;
    hasActiveSchedule?: boolean;
    schedulableActions?: string[];
}): ReactElement {
    const isEditingSchedule = editingSchedule != null;
    const resolvedCanSchedule = Boolean(
        canSchedule ?? movementContext.can_schedule ?? isEditingSchedule,
    );
    const resolvedHasActiveSchedule = Boolean(
        isEditingSchedule
            ? false
            : (hasActiveSchedule ?? movementContext.has_active_schedule),
    );
    const resolvedSchedulableActions =
        schedulableActions ??
        movementContext.schedulable_actions ??
        DEFAULT_SCHEDULABLE_ACTIONS;
    const firstFieldRef = useRef<HTMLInputElement | HTMLTextAreaElement | null>(
        null,
    );
    const [transferPromptOpen, setTransferPromptOpen] = useState(false);
    const [mode, setMode] = useState<'record_now' | 'schedule_later'>(
        isEditingSchedule ? 'schedule_later' : 'record_now',
    );
    const form = useForm<CrewMovementActionFormData>(
        applySchedulePrefill(
            buildInitialForm(
                action ?? 'approve_mobilisation',
                movementContext,
                formOptions,
                transferPrefill,
            ),
            editingSchedule,
        ),
    );

    useEffect(() => {
        if (!open || !action) {
            return;
        }

        form.clearErrors();
        form.setData(
            applySchedulePrefill(
                buildInitialForm(
                    action,
                    movementContext,
                    formOptions,
                    transferPrefill,
                ),
                editingSchedule,
            ),
        );
        setMode(isEditingSchedule ? 'schedule_later' : 'record_now');
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when dialog opens for an action
    }, [
        open,
        action,
        movementContext.assignment_id,
        transferPrefill,
        editingSchedule?.id,
    ]);

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

    const currentOnVessel = movementContext.active_on_vessel_elsewhere ?? null;
    const destinationVessel = formOptions?.vessels.find(
        (vessel) => vessel.id === form.data.vessel_id,
    );
    const recommendsTransfer =
        action === 'join_vessel' &&
        recommendsVesselTransfer(currentOnVessel, form.data.vessel_id);

    const schedulingMode =
        isEditingSchedule ||
        (mode === 'schedule_later' &&
            action !== null &&
            resolvedCanSchedule &&
            isSchedulableMovementAction(action, resolvedSchedulableActions));

    const submit = (): void => {
        if (!action) {
            return;
        }

        if (recommendsTransfer && !schedulingMode) {
            setTransferPromptOpen(true);

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

            if (action === 'record_arrival') {
                if (payload.no_hotel_accommodation) {
                    payload.accommodation_status = 'no_accommodation';
                    payload.hotel_id = null;
                    payload.room_type_id = null;
                    payload.check_in_date = '';
                } else {
                    payload.accommodation_status = 'hotel';
                }

                delete (payload as { no_hotel_accommodation?: boolean })
                    .no_hotel_accommodation;
            }

            if (action === 'confirm_disembarkation') {
                if (payload.next_phase === 'p6') {
                    delete (payload as { accommodation_status?: string })
                        .accommodation_status;
                    delete (payload as { hotel_id?: number | null }).hotel_id;
                    delete (payload as { room_type_id?: number | null })
                        .room_type_id;
                    delete (payload as { check_in_date?: string })
                        .check_in_date;
                } else if (payload.no_hotel_accommodation) {
                    payload.accommodation_status = 'no_accommodation';
                    payload.hotel_id = null;
                    payload.room_type_id = null;
                    payload.check_in_date = '';
                } else {
                    payload.accommodation_status = 'hotel';
                }

                delete (payload as { no_hotel_accommodation?: boolean })
                    .no_hotel_accommodation;
            }

            if (action === 'join_vessel') {
                if (
                    movementContext.pre_join_accommodation?.status !==
                    'open_hotel'
                ) {
                    delete (payload as { check_out_date?: string })
                        .check_out_date;
                }

                delete (payload as { accommodation_status?: string })
                    .accommodation_status;
                delete (payload as { hotel_id?: number | null }).hotel_id;
                delete (payload as { room_type_id?: number | null })
                    .room_type_id;
                delete (payload as { check_in_date?: string }).check_in_date;
                delete (payload as { no_hotel_accommodation?: boolean })
                    .no_hotel_accommodation;
            }

            if (action === 'travel_home') {
                if (
                    movementContext.post_signoff_accommodation?.status !==
                    'open_hotel'
                ) {
                    delete (payload as { check_out_date?: string })
                        .check_out_date;
                }

                delete (payload as { accommodation_status?: string })
                    .accommodation_status;
                delete (payload as { hotel_id?: number | null }).hotel_id;
                delete (payload as { room_type_id?: number | null })
                    .room_type_id;
                delete (payload as { check_in_date?: string }).check_in_date;
                delete (payload as { no_hotel_accommodation?: boolean })
                    .no_hotel_accommodation;
                delete (payload as { source_check_out_date?: string })
                    .source_check_out_date;
            }

            if (action === 'redeploy') {
                if (
                    movementContext.post_signoff_accommodation?.status !==
                    'open_hotel'
                ) {
                    delete (payload as { source_check_out_date?: string })
                        .source_check_out_date;
                }

                if (payload.starting_phase === 'p2a') {
                    if (payload.no_hotel_accommodation) {
                        payload.accommodation_status = 'no_accommodation';
                        payload.hotel_id = null;
                        payload.room_type_id = null;
                        payload.check_in_date = '';
                    } else {
                        payload.accommodation_status = 'hotel';
                    }
                } else {
                    delete (payload as { accommodation_status?: string })
                        .accommodation_status;
                    delete (payload as { hotel_id?: number | null }).hotel_id;
                    delete (payload as { room_type_id?: number | null })
                        .room_type_id;
                    delete (payload as { check_in_date?: string })
                        .check_in_date;
                }

                delete (payload as { check_out_date?: string }).check_out_date;
                delete (payload as { no_hotel_accommodation?: boolean })
                    .no_hotel_accommodation;
            }

            if (action === 'cancel_assignment') {
                const hasOpenPreJoin =
                    movementContext.pre_join_accommodation?.status ===
                    'open_hotel';
                const hasOpenPostSignoff =
                    movementContext.post_signoff_accommodation?.status ===
                    'open_hotel';

                if (!hasOpenPreJoin && !hasOpenPostSignoff) {
                    delete (payload as { check_out_date?: string })
                        .check_out_date;
                }

                delete (payload as { accommodation_status?: string })
                    .accommodation_status;
                delete (payload as { hotel_id?: number | null }).hotel_id;
                delete (payload as { room_type_id?: number | null })
                    .room_type_id;
                delete (payload as { check_in_date?: string }).check_in_date;
                delete (payload as { source_check_out_date?: string })
                    .source_check_out_date;
                delete (payload as { no_hotel_accommodation?: boolean })
                    .no_hotel_accommodation;
            }

            if (schedulingMode) {
                const checkOutAutoSynced =
                    action === 'join_vessel' &&
                    Boolean(payload.check_out_date) &&
                    payload.check_out_date === payload.occurred_at.slice(0, 10);
                const sourceCheckOutAutoSynced =
                    action === 'redeploy' &&
                    Boolean(payload.source_check_out_date) &&
                    payload.source_check_out_date ===
                        payload.occurred_at.slice(0, 10);

                if (isEditingSchedule && editingSchedule) {
                    return buildScheduledMovementEditPayload(action, payload, {
                        checkOutDateAutoSynced: checkOutAutoSynced,
                        sourceCheckOutDateAutoSynced: sourceCheckOutAutoSynced,
                    });
                }

                return {
                    ...payload,
                    mode: 'schedule_later' as const,
                    scheduled_at: payload.occurred_at,
                    check_out_date_auto_synced: checkOutAutoSynced,
                    source_check_out_date_auto_synced: sourceCheckOutAutoSynced,
                };
            }

            return payload;
        });

        const finish = {
            preserveScroll: true,
            onFinish: () => {
                form.transform((data) => data);
            },
            onSuccess: () => {
                onOpenChange(false);
            },
        };

        if (isEditingSchedule && editingSchedule) {
            form.put(updateScheduledMovement.url(editingSchedule.id), finish);

            return;
        }

        const url = schedulingMode
            ? storeScheduledMovement.url(assignmentId)
            : performAction.url(assignmentId);

        form.post(url, finish);
    };

    if (!action) {
        return (
            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent className="sm:max-w-lg" />
            </Dialog>
        );
    }

    const config = getMovementActionConfig(
        action,
        movementContext.current_phase_code,
    );
    const impactPreview = buildMovementImpactPreview({
        action,
        config,
        context: movementContext,
        formData: form.data,
        formOptions,
    });
    const isDestructive = Boolean(config.destructive);
    const isLarge =
        action === 'join_vessel' ||
        action === 'confirm_disembarkation' ||
        action === 'travel_home' ||
        action === 'transfer_vessel' ||
        action === 'redeploy';
    const cancelLabel = config.keepOpenLabel ?? 'Cancel';
    const canOfferSchedule =
        resolvedCanSchedule &&
        isSchedulableMovementAction(action, resolvedSchedulableActions) &&
        !isDestructive;
    const scheduleBlocked = canOfferSchedule && resolvedHasActiveSchedule;
    const submitLabel = isEditingSchedule
        ? 'Save Changes'
        : schedulingMode
          ? 'Save Schedule'
          : action === 'travel_home'
            ? form.data.completion_intent === 'redeploy'
                ? 'Move to Home / Redeployment'
                : 'Return Home & Close Assignment'
            : config.submitLabel;
    const effectiveConfig = schedulingMode
        ? {
              ...config,
              occurredAtLabel: config.occurredAtLabel
                  ? `Scheduled ${config.occurredAtLabel.replace(/^Actual\s+/i, '')}`
                  : 'Scheduled date and time',
              description: `${config.description} Schedule for later — no operational side effects until automatic execution.`,
          }
        : config;

    return (
        <Fragment>
            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent
                    className={cn(
                        'flex max-h-[90vh] flex-col gap-0 overflow-hidden glass-card p-0',
                        isLarge ? 'sm:max-w-2xl' : 'sm:max-w-lg',
                    )}
                >
                    <DialogHeader className="shrink-0 space-y-1.5 border-b border-border/60 px-6 py-4 text-left">
                        <div className="flex items-start gap-1">
                            <DialogTitle>{config.title}</DialogTitle>
                            {action === 'transfer_vessel' ? (
                                <MovementWorkflowHelp
                                    topic="transfer"
                                    label="Explain Transfer Vessel"
                                />
                            ) : null}
                            {action === 'redeploy' ? (
                                <MovementWorkflowHelp
                                    topic="redeploy"
                                    label="Explain Redeploy"
                                />
                            ) : null}
                        </div>
                        <DialogDescription>
                            {effectiveConfig.description}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                        {canOfferSchedule && !isEditingSchedule ? (
                            <div className="grid grid-cols-2 gap-2 rounded-lg border border-border/60 p-1">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                        mode === 'record_now'
                                            ? 'default'
                                            : 'ghost'
                                    }
                                    onClick={() => setMode('record_now')}
                                    disabled={form.processing}
                                >
                                    Record Now
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                        mode === 'schedule_later'
                                            ? 'default'
                                            : 'ghost'
                                    }
                                    onClick={() => setMode('schedule_later')}
                                    disabled={
                                        form.processing || scheduleBlocked
                                    }
                                >
                                    Schedule for Later
                                </Button>
                            </div>
                        ) : null}

                        {isEditingSchedule ? (
                            <div className="rounded-lg border border-sky-500/30 bg-sky-500/10 px-3 py-2 text-xs text-sky-950 dark:text-sky-100">
                                Editing the pending schedule. Supported movement
                                fields use the same validation as Schedule for
                                Later. Unchanged hotel dates keep manual
                                overrides.
                            </div>
                        ) : null}

                        {scheduleBlocked ? (
                            <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-100">
                                This assignment already has a pending scheduled
                                movement. Edit, reschedule, or cancel it before
                                creating another.
                            </div>
                        ) : null}

                        {schedulingMode ? (
                            <div className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                                {SCHEDULE_LATER_HELP} Expected next phase:{' '}
                                {impactPreview?.title ??
                                    effectiveConfig.nextPhaseLabel ??
                                    'operational transition'}
                                .
                            </div>
                        ) : null}

                        {shouldShowTestingOverrideBanner(
                            Boolean(
                                movementContext.allow_future_actual_movement_dates,
                            ),
                            schedulingMode,
                        ) ? (
                            <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-100">
                                {TESTING_OVERRIDE_BANNER_MESSAGE}
                            </div>
                        ) : null}

                        <MovementContextCard context={movementContext} />

                        <ActionForm
                            action={action}
                            form={form}
                            config={effectiveConfig}
                            context={movementContext}
                            formOptions={formOptions}
                            firstFieldRef={firstFieldRef}
                            schedulingMode={schedulingMode}
                        />

                        {impactPreview && !schedulingMode ? (
                            <ActionImpactPreview {...impactPreview} />
                        ) : null}

                        {schedulingMode && impactPreview ? (
                            <ActionImpactPreview
                                {...impactPreview}
                                title="Scheduled outcome (at execution)"
                                warning="These changes apply only when the schedule executes successfully."
                            />
                        ) : null}

                        <InputError
                            message={
                                'error' in form.errors
                                    ? mapMovementErrorMessage(
                                          String(form.errors.error ?? ''),
                                      )
                                    : undefined
                            }
                        />
                        <InputError message={form.errors.action} />
                        <InputError message={form.errors.mode} />
                        <InputError message={form.errors.scheduled_at} />
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
                            variant={
                                isDestructive && !schedulingMode
                                    ? 'destructive'
                                    : 'default'
                            }
                            onClick={submit}
                            disabled={
                                form.processing ||
                                (schedulingMode && scheduleBlocked)
                            }
                        >
                            {form.processing ? (
                                <Spinner className="mr-2" />
                            ) : null}
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <VesselTransferRecommendationDialog
                open={transferPromptOpen}
                onOpenChange={setTransferPromptOpen}
                current={currentOnVessel}
                destinationVesselName={destinationVessel?.name}
                prefill={{
                    vessel_id: form.data.vessel_id,
                    position_id: form.data.position_id,
                    client_id: form.data.client_id,
                    occurred_at: form.data.occurred_at,
                }}
            />
        </Fragment>
    );
}
