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
import { CREW_PHASE_LABELS } from '../../types';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

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
    const signoffBeforeRedeploy =
        form.data.planned_signoff_at &&
        redeployDate &&
        form.data.planned_signoff_at < redeployDate;

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
                    onValueChange={(value) =>
                        form.setData('starting_phase', value)
                    }
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
                                    form.setData(
                                        'rank_id',
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
                        form.setData('planned_signoff_at', event.target.value)
                    }
                />
                {signoffBeforeRedeploy ? (
                    <p className="text-sm text-destructive">
                        The planned sign-off cannot be before the redeployment
                        date.
                    </p>
                ) : null}
                <InputError message={form.errors.planned_signoff_at} />
            </div>

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
