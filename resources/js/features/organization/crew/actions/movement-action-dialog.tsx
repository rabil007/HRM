import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useEffect } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { performAction } from '@/routes/organization/crew-assignments';
import type {
    CrewAssignmentFormOptions,
    CrewMovementAction,
    CrewMovementActionFormData,
} from '../types';
import {
    CREW_MOVEMENT_ACTION_LABELS,
    CREW_PHASE_LABELS,
} from '../types';

const NEXT_PHASE_OPTIONS: Partial<
    Record<CrewMovementAction, Array<{ value: string; label: string }>>
> = {
    record_arrival: [
        { value: 'p2a', label: CREW_PHASE_LABELS.p2a },
        { value: 'p3', label: CREW_PHASE_LABELS.p3 },
    ],
    complete_training: [
        { value: 'p2a', label: CREW_PHASE_LABELS.p2a },
        { value: 'p3', label: CREW_PHASE_LABELS.p3 },
    ],
    confirm_disembarkation: [
        { value: 'p5', label: CREW_PHASE_LABELS.p5 },
        { value: 'p6', label: CREW_PHASE_LABELS.p6 },
    ],
};

const EXPECTED_NEXT_PHASE: Partial<Record<CrewMovementAction, string>> = {
    approve_mobilisation: 'p1',
    start_join_standby: 'p2a',
    send_to_training: 'p2b',
    mark_ready: 'p3',
    join_vessel: 'p4',
    plan_signoff: 'p4',
    start_demob_standby: 'p5',
    travel_home: 'p6',
};

function defaultDateTimeLocal(): string {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());

    return now.toISOString().slice(0, 16);
}

function buildInitialForm(
    action: CrewMovementAction,
    defaults?: {
        vesselId?: number | null;
        rankId?: number | null;
        clientId?: number | null;
        visaTypeId?: number | null;
        plannedSignoffAt?: string | null;
    },
): CrewMovementActionFormData {
    const nextPhaseOptions = NEXT_PHASE_OPTIONS[action];

    return {
        action,
        occurred_at: defaultDateTimeLocal(),
        next_phase: nextPhaseOptions?.[0]?.value ?? '',
        provider: '',
        course: '',
        planned_start_at: '',
        planned_end_at: '',
        remarks: '',
        vessel_id: defaults?.vesselId ?? null,
        rank_id: defaults?.rankId ?? null,
        client_id: defaults?.clientId ?? null,
        company_visa_type_id: defaults?.visaTypeId ?? null,
        planned_signoff_at: defaults?.plannedSignoffAt ?? '',
        planned_travel_at: '',
        reason: '',
    };
}

function actionNeedsOccurredAt(action: CrewMovementAction): boolean {
    return action !== 'plan_signoff';
}

function actionNeedsNextPhase(action: CrewMovementAction): boolean {
    return (
        action === 'record_arrival' ||
        action === 'complete_training' ||
        action === 'confirm_disembarkation'
    );
}

function resolveExpectedNextPhaseLabel(
    action: CrewMovementAction,
    nextPhase: string,
): string | null {
    if (actionNeedsNextPhase(action)) {
        return nextPhase ? (CREW_PHASE_LABELS[nextPhase] ?? nextPhase) : null;
    }

    if (action === 'cancel_assignment' || action === 'close_assignment') {
        return null;
    }

    const code = EXPECTED_NEXT_PHASE[action];

    return code ? CREW_PHASE_LABELS[code] : null;
}

export function MovementActionDialog({
    open,
    onOpenChange,
    action,
    assignmentId,
    currentPhase,
    formOptions,
    defaultVesselId,
    defaultRankId,
    defaultClientId,
    defaultVisaTypeId,
    defaultPlannedSignoffAt,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    action: CrewMovementAction | null;
    assignmentId: number;
    currentPhase: { code: string; label: string } | null;
    formOptions?: CrewAssignmentFormOptions;
    defaultVesselId?: number | null;
    defaultRankId?: number | null;
    defaultClientId?: number | null;
    defaultVisaTypeId?: number | null;
    defaultPlannedSignoffAt?: string | null;
}): ReactElement {
    const form = useForm<CrewMovementActionFormData>(
        buildInitialForm(action ?? 'approve_mobilisation'),
    );

    useEffect(() => {
        if (!open || !action) {
            return;
        }

        form.clearErrors();
        form.setData(
            buildInitialForm(action, {
                vesselId: defaultVesselId,
                rankId: defaultRankId,
                clientId: defaultClientId,
                visaTypeId: defaultVisaTypeId,
                plannedSignoffAt: defaultPlannedSignoffAt,
            }),
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when dialog opens for an action
    }, [open, action]);

    const handleOpenChange = (nextOpen: boolean): void => {
        onOpenChange(nextOpen);
    };

    const submit = (): void => {
        if (!action) {
            return;
        }

        form.post(performAction.url(assignmentId), {
            preserveScroll: true,
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

    const isDestructive = action === 'cancel_assignment';
    const expectedNextPhase = resolveExpectedNextPhaseLabel(
        action,
        form.data.next_phase,
    );
    const nextPhaseOptions = NEXT_PHASE_OPTIONS[action] ?? [];

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="glass-card sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {CREW_MOVEMENT_ACTION_LABELS[action]}
                    </DialogTitle>
                    <DialogDescription>
                        Record this crew movement action for the assignment.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                        <div>
                            <span className="text-muted-foreground">
                                Current phase:{' '}
                            </span>
                            <span className="font-medium">
                                {currentPhase
                                    ? `${currentPhase.code.toUpperCase()} · ${currentPhase.label}`
                                    : 'None'}
                            </span>
                        </div>
                        {expectedNextPhase ? (
                            <div className="mt-1">
                                <span className="text-muted-foreground">
                                    Expected next phase:{' '}
                                </span>
                                <span className="font-medium">
                                    {expectedNextPhase}
                                </span>
                            </div>
                        ) : null}
                    </div>

                    {actionNeedsNextPhase(action) ? (
                        <div className="space-y-2">
                            <Label htmlFor="movement-next-phase">
                                Next phase
                            </Label>
                            <Select
                                value={form.data.next_phase}
                                onValueChange={(value) =>
                                    form.setData('next_phase', value)
                                }
                            >
                                <SelectTrigger id="movement-next-phase">
                                    <SelectValue placeholder="Select next phase..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {nextPhaseOptions.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.next_phase} />
                        </div>
                    ) : null}

                    {actionNeedsOccurredAt(action) ? (
                        <div className="space-y-2">
                            <Label htmlFor="movement-occurred-at">
                                Occurred at
                            </Label>
                            <Input
                                id="movement-occurred-at"
                                type="datetime-local"
                                value={form.data.occurred_at}
                                onChange={(event) =>
                                    form.setData(
                                        'occurred_at',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.occurred_at} />
                        </div>
                    ) : null}

                    {action === 'send_to_training' ? (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="movement-provider">
                                        Provider
                                    </Label>
                                    <Input
                                        id="movement-provider"
                                        value={form.data.provider}
                                        onChange={(event) =>
                                            form.setData(
                                                'provider',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors.provider} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="movement-course">
                                        Course
                                    </Label>
                                    <Input
                                        id="movement-course"
                                        value={form.data.course}
                                        onChange={(event) =>
                                            form.setData(
                                                'course',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors.course} />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="movement-planned-start">
                                        Planned start
                                    </Label>
                                    <Input
                                        id="movement-planned-start"
                                        type="datetime-local"
                                        value={form.data.planned_start_at}
                                        onChange={(event) =>
                                            form.setData(
                                                'planned_start_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.planned_start_at}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="movement-planned-end">
                                        Planned end
                                    </Label>
                                    <Input
                                        id="movement-planned-end"
                                        type="datetime-local"
                                        value={form.data.planned_end_at}
                                        onChange={(event) =>
                                            form.setData(
                                                'planned_end_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.planned_end_at}
                                    />
                                </div>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="movement-training-remarks">
                                    Remarks
                                </Label>
                                <Textarea
                                    id="movement-training-remarks"
                                    value={form.data.remarks}
                                    onChange={(event) =>
                                        form.setData(
                                            'remarks',
                                            event.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                                <InputError message={form.errors.remarks} />
                            </div>
                        </>
                    ) : null}

                    {action === 'join_vessel' && formOptions ? (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="movement-vessel">
                                        Vessel
                                    </Label>
                                    <Select
                                        value={
                                            form.data.vessel_id?.toString() ??
                                            ''
                                        }
                                        onValueChange={(value) =>
                                            form.setData(
                                                'vessel_id',
                                                value ? Number(value) : null,
                                            )
                                        }
                                    >
                                        <SelectTrigger id="movement-vessel">
                                            <SelectValue placeholder="Select vessel..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {formOptions.vessels.map(
                                                (vessel) => (
                                                    <SelectItem
                                                        key={vessel.id}
                                                        value={vessel.id.toString()}
                                                    >
                                                        {vessel.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={form.errors.vessel_id}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="movement-rank">Rank</Label>
                                    <Select
                                        value={
                                            form.data.rank_id?.toString() ?? ''
                                        }
                                        onValueChange={(value) =>
                                            form.setData(
                                                'rank_id',
                                                value ? Number(value) : null,
                                            )
                                        }
                                    >
                                        <SelectTrigger id="movement-rank">
                                            <SelectValue placeholder="Select rank..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {formOptions.ranks.map((rank) => (
                                                <SelectItem
                                                    key={rank.id}
                                                    value={rank.id.toString()}
                                                >
                                                    {rank.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors.rank_id} />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="movement-client">
                                        Client
                                    </Label>
                                    <Select
                                        value={
                                            form.data.client_id?.toString() ??
                                            ''
                                        }
                                        onValueChange={(value) =>
                                            form.setData(
                                                'client_id',
                                                value ? Number(value) : null,
                                            )
                                        }
                                    >
                                        <SelectTrigger id="movement-client">
                                            <SelectValue placeholder="Select client..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {formOptions.clients.map(
                                                (client) => (
                                                    <SelectItem
                                                        key={client.id}
                                                        value={client.id.toString()}
                                                    >
                                                        {client.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={form.errors.client_id}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="movement-visa">
                                        Visa type
                                    </Label>
                                    <Select
                                        value={
                                            form.data.company_visa_type_id?.toString() ??
                                            ''
                                        }
                                        onValueChange={(value) =>
                                            form.setData(
                                                'company_visa_type_id',
                                                value ? Number(value) : null,
                                            )
                                        }
                                    >
                                        <SelectTrigger id="movement-visa">
                                            <SelectValue placeholder="Select visa type..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {formOptions.visa_types.map(
                                                (visaType) => (
                                                    <SelectItem
                                                        key={visaType.id}
                                                        value={visaType.id.toString()}
                                                    >
                                                        {visaType.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={
                                            form.errors.company_visa_type_id
                                        }
                                    />
                                </div>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="movement-planned-signoff">
                                    Planned sign-off
                                </Label>
                                <Input
                                    id="movement-planned-signoff"
                                    type="date"
                                    value={form.data.planned_signoff_at}
                                    onChange={(event) =>
                                        form.setData(
                                            'planned_signoff_at',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.planned_signoff_at}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="movement-join-remarks">
                                    Remarks
                                </Label>
                                <Textarea
                                    id="movement-join-remarks"
                                    value={form.data.remarks}
                                    onChange={(event) =>
                                        form.setData(
                                            'remarks',
                                            event.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                                <InputError message={form.errors.remarks} />
                            </div>
                        </>
                    ) : null}

                    {action === 'plan_signoff' ? (
                        <>
                            <div className="rounded-lg border border-warning/30 bg-warning/10 p-3 text-sm text-warning">
                                This updates the planned sign-off date while the
                                crew member remains on vessel (P4).
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="movement-plan-signoff">
                                    Planned sign-off
                                </Label>
                                <Input
                                    id="movement-plan-signoff"
                                    type="date"
                                    value={form.data.planned_signoff_at}
                                    onChange={(event) =>
                                        form.setData(
                                            'planned_signoff_at',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.planned_signoff_at}
                                />
                            </div>
                        </>
                    ) : null}

                    {action === 'travel_home' ? (
                        <div className="space-y-2">
                            <Label htmlFor="movement-planned-travel">
                                Planned travel
                            </Label>
                            <Input
                                id="movement-planned-travel"
                                type="datetime-local"
                                value={form.data.planned_travel_at}
                                onChange={(event) =>
                                    form.setData(
                                        'planned_travel_at',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.planned_travel_at}
                            />
                        </div>
                    ) : null}

                    {action === 'cancel_assignment' ? (
                        <div className="space-y-2">
                            <Label htmlFor="movement-reason">
                                Cancellation reason
                            </Label>
                            <Textarea
                                id="movement-reason"
                                value={form.data.reason}
                                onChange={(event) =>
                                    form.setData('reason', event.target.value)
                                }
                                rows={3}
                            />
                            <InputError message={form.errors.reason} />
                        </div>
                    ) : null}

                    <InputError
                        message={
                            'error' in form.errors
                                ? String(form.errors.error ?? '')
                                : undefined
                        }
                    />
                    <InputError message={form.errors.action} />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant={isDestructive ? 'destructive' : 'default'}
                        onClick={submit}
                        disabled={form.processing}
                    >
                        {form.processing ? <Spinner className="mr-2" /> : null}
                        {CREW_MOVEMENT_ACTION_LABELS[action]}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
