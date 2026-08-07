import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    clearedDirectP4TourFields,
    defaultDestinationTourSignoffChoice,
    findRankTourOption,
    hasManualOverrideInput,
    nextSignoffChoiceForRankChange,
} from '@/features/organization/crew/lib/tour-signoff';
import { CREW_PHASE_LABELS } from '../../types';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';
import { TourSignoffFields } from './tour-signoff-fields';

const REDEPLOY_STARTING_PHASES = [
    { value: 'p0', label: CREW_PHASE_LABELS.p0 },
    { value: 'p1', label: CREW_PHASE_LABELS.p1 },
    { value: 'p2a', label: CREW_PHASE_LABELS.p2a },
    { value: 'p3', label: CREW_PHASE_LABELS.p3 },
    { value: 'p4', label: CREW_PHASE_LABELS.p4 },
] as const;

export function RedeployForm({
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const startingPhase = form.data.starting_phase;
    const requiresVessel = startingPhase === 'p4';
    const redeployDate = form.data.occurred_at.slice(0, 10);
    const showDestinationFields = ['p1', 'p2a', 'p3', 'p4'].includes(
        startingPhase,
    );
    const showDirectP4Tour = startingPhase === 'p4';
    const showForecastSignoff = ['p1', 'p2a', 'p3'].includes(startingPhase);
    const selectedRank = findRankTourOption(
        formOptions?.ranks,
        form.data.rank_id,
    );
    const signoffBeforeRedeploy =
        form.data.planned_signoff_at &&
        redeployDate &&
        form.data.planned_signoff_at < redeployDate;

    const setDestinationRank = (rankId: number | null): void => {
        if (!showDirectP4Tour) {
            form.setData('rank_id', rankId);

            return;
        }

        const nextRank = findRankTourOption(formOptions?.ranks, rankId);
        const planned_signoff_choice = nextSignoffChoiceForRankChange({
            previousChoice: form.data.planned_signoff_choice,
            nextRank,
            hasManualOverrideInput: hasManualOverrideInput(form.data),
        });

        form.setData({
            ...form.data,
            rank_id: rankId,
            planned_signoff_choice,
        });
    };

    return (
        <div className="space-y-4">
            <div className="space-y-1 rounded-lg border bg-muted/20 p-3 text-sm">
                <div>
                    <span className="text-muted-foreground">Employee: </span>
                    <span className="font-medium">
                        {[context.employee_name, context.employee_no]
                            .filter(Boolean)
                            .join(' · ') || '—'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Current phase:{' '}
                    </span>
                    <span className="font-medium">
                        {context.current_phase_code
                            ? `${context.current_phase_code.toUpperCase()} · ${context.current_phase_label ?? ''}`
                            : 'None'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Previous vessel:{' '}
                    </span>
                    <span className="font-medium">
                        {context.vessel_name ?? 'Not set'}
                    </span>
                </div>
            </div>

            <div className="space-y-2">
                <Label htmlFor="redeploy-starting-phase">
                    Starting phase <span className="text-destructive">*</span>
                </Label>
                <Select
                    value={startingPhase}
                    onValueChange={(value) => {
                        const next: Partial<typeof form.data> = {
                            starting_phase: value,
                        };

                        if (value === 'p0') {
                            next.vessel_id = null;
                            next.rank_id = null;
                            next.client_id = null;
                            next.company_visa_type_id = null;
                            next.planned_signoff_at = '';
                            Object.assign(next, clearedDirectP4TourFields());
                        } else if (value === 'p4') {
                            if (
                                !['p1', 'p2a', 'p3', 'p4'].includes(
                                    startingPhase,
                                )
                            ) {
                                next.vessel_id = context.vessel_id;
                                next.rank_id = context.rank_id;
                                next.client_id = context.client_id;
                                next.company_visa_type_id =
                                    context.visa_type_id;
                            }

                            const rankId =
                                next.rank_id !== undefined
                                    ? next.rank_id
                                    : form.data.rank_id;
                            const rank = findRankTourOption(
                                formOptions?.ranks,
                                rankId,
                            );

                            next.planned_signoff_choice =
                                defaultDestinationTourSignoffChoice(rank);
                            next.tour_of_duty_days = '';
                            next.planned_signoff_override_reason = '';
                            next.planned_signoff_at = '';
                        } else {
                            if (
                                !['p1', 'p2a', 'p3', 'p4'].includes(
                                    startingPhase,
                                )
                            ) {
                                next.vessel_id = context.vessel_id;
                                next.rank_id = context.rank_id;
                                next.client_id = context.client_id;
                                next.company_visa_type_id =
                                    context.visa_type_id;
                            }

                            Object.assign(next, clearedDirectP4TourFields());
                        }

                        form.setData({ ...form.data, ...next });
                    }}
                >
                    <SelectTrigger id="redeploy-starting-phase">
                        <SelectValue placeholder="Select starting phase..." />
                    </SelectTrigger>
                    <SelectContent>
                        {REDEPLOY_STARTING_PHASES.map((phase) => (
                            <SelectItem key={phase.value} value={phase.value}>
                                {phase.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                    Only the chosen starting phase is created. Earlier phases
                    are not invented.
                </p>
                <InputError message={form.errors.starting_phase} />
            </div>

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    inputRef={firstFieldRef}
                />
            ) : null}

            {formOptions && showDestinationFields ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="redeploy-vessel">
                                Destination vessel
                                {requiresVessel ? (
                                    <span className="text-destructive"> *</span>
                                ) : (
                                    ' (optional)'
                                )}
                            </Label>
                            <Select
                                value={form.data.vessel_id?.toString() ?? ''}
                                onValueChange={(value) =>
                                    form.setData(
                                        'vessel_id',
                                        value ? Number(value) : null,
                                    )
                                }
                            >
                                <SelectTrigger id="redeploy-vessel">
                                    <SelectValue placeholder="Select vessel..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {formOptions.vessels.map((vessel) => (
                                        <SelectItem
                                            key={vessel.id}
                                            value={vessel.id.toString()}
                                        >
                                            {vessel.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.vessel_id} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="redeploy-rank">
                                Destination rank
                                {requiresVessel ? (
                                    <span className="text-destructive"> *</span>
                                ) : (
                                    ' (optional)'
                                )}
                            </Label>
                            <Select
                                value={form.data.rank_id?.toString() ?? ''}
                                onValueChange={(value) =>
                                    setDestinationRank(
                                        value ? Number(value) : null,
                                    )
                                }
                            >
                                <SelectTrigger id="redeploy-rank">
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
                            <Label htmlFor="redeploy-client">
                                Destination client / project (optional)
                            </Label>
                            <Select
                                value={form.data.client_id?.toString() ?? ''}
                                onValueChange={(value) =>
                                    form.setData(
                                        'client_id',
                                        value ? Number(value) : null,
                                    )
                                }
                            >
                                <SelectTrigger id="redeploy-client">
                                    <SelectValue placeholder="Select client..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {formOptions.clients.map((client) => (
                                        <SelectItem
                                            key={client.id}
                                            value={client.id.toString()}
                                        >
                                            {client.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.client_id} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="redeploy-visa">
                                Visa type (optional)
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
                                <SelectTrigger id="redeploy-visa">
                                    <SelectValue placeholder="Select visa type..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {formOptions.visa_types.map((visaType) => (
                                        <SelectItem
                                            key={visaType.id}
                                            value={visaType.id.toString()}
                                        >
                                            {visaType.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={form.errors.company_visa_type_id}
                            />
                        </div>
                    </div>
                </>
            ) : null}

            {showDirectP4Tour ? (
                <TourSignoffFields
                    form={form}
                    selectedRank={selectedRank}
                    occurredDate={redeployDate}
                    allowExistingPlan={false}
                    idPrefix="redeploy"
                    tourContextLabel="the destination rank"
                />
            ) : null}

            {showForecastSignoff ? (
                <div className="space-y-2">
                    <Label htmlFor="redeploy-planned-signoff">
                        Planned Sign-Off (optional)
                    </Label>
                    <Input
                        id="redeploy-planned-signoff"
                        type="date"
                        value={form.data.planned_signoff_at}
                        min={redeployDate || undefined}
                        onChange={(event) =>
                            form.setData(
                                'planned_signoff_at',
                                event.target.value,
                            )
                        }
                    />
                    {signoffBeforeRedeploy ? (
                        <p className="text-sm text-destructive">
                            The planned sign-off cannot be before the
                            redeployment date.
                        </p>
                    ) : null}
                    <p className="text-xs text-muted-foreground">
                        Forecast only. Tour of Duty is set when the assignment
                        joins the vessel.
                    </p>
                    <InputError message={form.errors.planned_signoff_at} />
                </div>
            ) : null}

            <div className="space-y-2">
                <Label htmlFor="redeploy-remarks">Remarks (optional)</Label>
                <Textarea
                    id="redeploy-remarks"
                    value={form.data.remarks}
                    onChange={(event) =>
                        form.setData('remarks', event.target.value)
                    }
                    rows={3}
                />
                <InputError message={form.errors.remarks} />
            </div>
        </div>
    );
}
