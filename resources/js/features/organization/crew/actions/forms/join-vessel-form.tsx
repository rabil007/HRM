import {
    Root as RadioGroup,
    Item as RadioItem,
} from '@radix-ui/react-radio-group';
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
    resolveJoinTourDays,
    resolveJoinTourSource,
    suggestedPlannedSignoffDate,
    tourSourceLabel,
} from '@/features/organization/crew/lib/tour-of-duty';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function JoinVesselForm({
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const joinDate = form.data.occurred_at.slice(0, 10);
    const selectedRank = formOptions?.ranks.find(
        (rank) => rank.id === form.data.rank_id,
    );
    const tourDays = resolveJoinTourDays(
        selectedRank,
        form.data.tour_of_duty_days,
    );
    const tourSource = resolveJoinTourSource(
        selectedRank,
        form.data.tour_of_duty_days,
    );
    const suggestedSignoff =
        joinDate && tourDays != null
            ? suggestedPlannedSignoffDate(joinDate, tourDays)
            : null;
    const existingPlanned = context.planned_signoff_at ?? '';
    const choice = form.data.planned_signoff_choice;
    const hasExistingPlan = Boolean(existingPlanned);
    const hasTourSuggestion = tourDays != null && suggestedSignoff != null;

    const signoffBeforeJoin =
        choice === 'manual_override' &&
        form.data.planned_signoff_at &&
        joinDate &&
        form.data.planned_signoff_at < joinDate;

    const existingDiffersFromSuggestion =
        hasExistingPlan &&
        suggestedSignoff != null &&
        existingPlanned !== suggestedSignoff;

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
                        Current vessel plan:{' '}
                    </span>
                    <span className="font-medium">
                        {context.vessel_name ?? 'Not set'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Current rank:{' '}
                    </span>
                    <span className="font-medium">
                        {context.rank_name ?? 'Not set'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Planned join:{' '}
                    </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.planned_join_at)}
                    </span>
                </div>
            </div>

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    inputRef={firstFieldRef}
                />
            ) : null}

            {formOptions ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="movement-vessel">
                                Vessel{' '}
                                <span className="text-destructive">*</span>
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
                                <SelectTrigger id="movement-vessel">
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
                            <p className="text-xs text-muted-foreground">
                                The vessel on which the employee physically
                                joins.
                            </p>
                            <InputError message={form.errors.vessel_id} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="movement-rank">
                                Rank <span className="text-destructive">*</span>
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
                            <p className="text-xs text-muted-foreground">
                                The rank served onboard. This is used for
                                Planning and Sea Service.
                            </p>
                            <InputError message={form.errors.rank_id} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="movement-client">
                                Client (optional)
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
                                <SelectTrigger id="movement-client">
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
                            <Label htmlFor="movement-visa">
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
                                <SelectTrigger id="movement-visa">
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

            <div className="space-y-3 rounded-lg border border-border/80 bg-muted/10 p-4">
                <div>
                    <p className="text-sm font-medium text-foreground">
                        Tour of Duty
                    </p>
                    {tourDays != null ? (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {tourDays} days · {tourSourceLabel(tourSource)}
                        </p>
                    ) : (
                        <p className="mt-1 text-sm text-amber-700 dark:text-amber-300">
                            No Tour of Duty rule is configured for this rank.
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="movement-tour-override">
                        Assignment override (optional)
                    </Label>
                    <Input
                        id="movement-tour-override"
                        type="number"
                        min={1}
                        max={365}
                        placeholder="Use rank default"
                        value={
                            form.data.tour_of_duty_days === null
                                ? ''
                                : form.data.tour_of_duty_days
                        }
                        onChange={(event) => {
                            const value = event.target.value;

                            form.setData(
                                'tour_of_duty_days',
                                value === '' ? '' : Number(value),
                            );
                        }}
                    />
                    <p className="text-xs text-muted-foreground">
                        Override the rank Tour of Duty for this assignment only.
                    </p>
                    <InputError message={form.errors.tour_of_duty_days} />
                </div>

                {hasTourSuggestion ? (
                    <div className="rounded-md border border-primary/20 bg-primary/5 px-3 py-2 text-sm">
                        <span className="text-muted-foreground">
                            Suggested Planned Sign-Off:{' '}
                        </span>
                        <span className="font-medium text-foreground">
                            {formatDisplayDate(suggestedSignoff)}
                        </span>
                    </div>
                ) : null}

                {existingDiffersFromSuggestion ? (
                    <p className="text-xs text-amber-700 dark:text-amber-300">
                        Existing planned sign-off (
                        {formatDisplayDate(existingPlanned)}) differs from the
                        Tour of Duty suggestion (
                        {formatDisplayDate(suggestedSignoff)}).
                    </p>
                ) : null}
            </div>

            <div className="space-y-2">
                <Label id="movement-signoff-choice-label">
                    Planned Sign-Off date choice
                </Label>
                <RadioGroup
                    id="movement-signoff-choice"
                    value={choice}
                    onValueChange={(value) =>
                        form.setData(
                            'planned_signoff_choice',
                            value as typeof choice,
                        )
                    }
                    aria-labelledby="movement-signoff-choice-label"
                    className="grid gap-2"
                >
                    {hasTourSuggestion ? (
                        <RadioItem
                            value="tour_of_duty"
                            className={cn(
                                'rounded-lg border bg-background p-3 text-left transition-colors outline-none',
                                'hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring/40',
                                choice === 'tour_of_duty'
                                    ? 'border-primary ring-1 ring-primary'
                                    : 'border-border/80',
                            )}
                        >
                            <div className="text-sm font-medium">
                                Use Tour of Duty suggestion
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {formatDisplayDate(suggestedSignoff)} based on
                                join date and {tourDays} day tour.
                            </p>
                        </RadioItem>
                    ) : null}

                    {hasExistingPlan ? (
                        <RadioItem
                            value="existing_plan"
                            className={cn(
                                'rounded-lg border bg-background p-3 text-left transition-colors outline-none',
                                'hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring/40',
                                choice === 'existing_plan'
                                    ? 'border-primary ring-1 ring-primary'
                                    : 'border-border/80',
                            )}
                        >
                            <div className="text-sm font-medium">
                                Keep existing planned sign-off
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {formatDisplayDate(existingPlanned)}
                            </p>
                        </RadioItem>
                    ) : null}

                    <RadioItem
                        value="manual_override"
                        className={cn(
                            'rounded-lg border bg-background p-3 text-left transition-colors outline-none',
                            'hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring/40',
                            choice === 'manual_override'
                                ? 'border-primary ring-1 ring-primary'
                                : 'border-border/80',
                        )}
                    >
                        <div className="text-sm font-medium">
                            Enter another date
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Set a custom planned sign-off with a reason.
                        </p>
                    </RadioItem>
                </RadioGroup>
                <InputError message={form.errors.planned_signoff_choice} />
            </div>

            {choice === 'manual_override' ? (
                <>
                    <div className="space-y-2">
                        <Label htmlFor="movement-planned-signoff">
                            Planned Sign-Off{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="movement-planned-signoff"
                            type="date"
                            value={form.data.planned_signoff_at}
                            min={joinDate || undefined}
                            onChange={(event) =>
                                form.setData(
                                    'planned_signoff_at',
                                    event.target.value,
                                )
                            }
                        />
                        {signoffBeforeJoin ? (
                            <p className="text-sm text-destructive">
                                The planned sign-off cannot be before the actual
                                vessel join date.
                            </p>
                        ) : null}
                        <InputError message={form.errors.planned_signoff_at} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="movement-signoff-reason">
                            Override reason{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Textarea
                            id="movement-signoff-reason"
                            value={form.data.planned_signoff_override_reason}
                            onChange={(event) =>
                                form.setData(
                                    'planned_signoff_override_reason',
                                    event.target.value,
                                )
                            }
                            rows={2}
                        />
                        <InputError
                            message={
                                form.errors.planned_signoff_override_reason
                            }
                        />
                    </div>
                </>
            ) : null}

            <div className="space-y-2">
                <Label htmlFor="movement-join-remarks">
                    Remarks (optional)
                </Label>
                <Textarea
                    id="movement-join-remarks"
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
