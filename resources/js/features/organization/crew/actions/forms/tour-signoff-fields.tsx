import type { InertiaFormProps } from '@inertiajs/react';
import {
    Root as RadioGroup,
    Item as RadioItem,
} from '@radix-ui/react-radio-group';
import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    resolveJoinTourDays,
    suggestedPlannedSignoffDate,
} from '@/features/organization/crew/lib/tour-of-duty';
import type { CrewRankTourOption } from '@/features/organization/crew/lib/tour-signoff';
import type { CrewMovementActionFormData } from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function TourSignoffFields({
    form,
    selectedRank,
    occurredDate,
    existingPlannedSignoffAt = null,
    allowExistingPlan = false,
    idPrefix = 'movement',
    tourContextLabel = 'this rank',
}: {
    form: InertiaFormProps<CrewMovementActionFormData>;
    selectedRank: CrewRankTourOption | undefined;
    occurredDate: string;
    existingPlannedSignoffAt?: string | null;
    allowExistingPlan?: boolean;
    idPrefix?: string;
    /** e.g. "destination rank" for transfer/redeploy copy */
    tourContextLabel?: string;
}): ReactElement {
    const tourDays = resolveJoinTourDays(selectedRank);
    const suggestedSignoff =
        occurredDate && tourDays != null
            ? suggestedPlannedSignoffDate(occurredDate, tourDays)
            : null;
    const existingPlanned = existingPlannedSignoffAt ?? '';
    const choice = form.data.planned_signoff_choice;
    const hasExistingPlan = allowExistingPlan && Boolean(existingPlanned);
    const hasTourSuggestion = tourDays != null && suggestedSignoff != null;

    const signoffBeforeOccurred =
        choice === 'manual_override' &&
        form.data.planned_signoff_at &&
        occurredDate &&
        form.data.planned_signoff_at < occurredDate;

    const existingDiffersFromSuggestion =
        hasExistingPlan &&
        suggestedSignoff != null &&
        existingPlanned !== suggestedSignoff;

    return (
        <>
            <div className="space-y-3 rounded-lg border border-border/80 bg-muted/10 p-4">
                <div>
                    <p className="text-sm font-medium text-foreground">
                        Tour of Duty
                    </p>
                    {tourDays != null ? (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {tourDays} days · Based on Rank Master
                        </p>
                    ) : (
                        <p className="mt-1 text-sm text-amber-700 dark:text-amber-300">
                            No Tour of Duty rule is configured for{' '}
                            {tourContextLabel}.
                        </p>
                    )}
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
                <Label id={`${idPrefix}-signoff-choice-label`}>
                    Planned Sign-Off date choice
                </Label>
                <RadioGroup
                    id={`${idPrefix}-signoff-choice`}
                    value={choice}
                    onValueChange={(value) =>
                        form.setData(
                            'planned_signoff_choice',
                            value as typeof choice,
                        )
                    }
                    aria-labelledby={`${idPrefix}-signoff-choice-label`}
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
                                start date and {tourDays} day tour.
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
                        <Label htmlFor={`${idPrefix}-planned-signoff`}>
                            Planned Sign-Off{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id={`${idPrefix}-planned-signoff`}
                            type="date"
                            value={form.data.planned_signoff_at}
                            min={occurredDate || undefined}
                            onChange={(event) =>
                                form.setData(
                                    'planned_signoff_at',
                                    event.target.value,
                                )
                            }
                        />
                        {signoffBeforeOccurred ? (
                            <p className="text-sm text-destructive">
                                The planned sign-off cannot be before the start
                                date.
                            </p>
                        ) : null}
                        <InputError message={form.errors.planned_signoff_at} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor={`${idPrefix}-signoff-reason`}>
                            Override reason{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Textarea
                            id={`${idPrefix}-signoff-reason`}
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
        </>
    );
}
