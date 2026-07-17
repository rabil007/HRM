import type { ReactElement } from 'react';
import { formatDisplayDate } from '@/lib/format-date';
import { formatDaysOnboard } from '../../format-days-in-phase';
import { MovementNextPhaseChoice } from '../movement-next-phase-choice';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function ConfirmDisembarkationForm({
    form,
    config,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const disembarkDate = form.data.occurred_at.slice(0, 10);
    const beforeJoin =
        context.actual_join_at &&
        disembarkDate &&
        disembarkDate < context.actual_join_at;

    return (
        <div className="space-y-4">
            <div className="space-y-1 rounded-lg border bg-muted/20 p-3 text-sm">
                <div>
                    <span className="text-muted-foreground">Vessel: </span>
                    <span className="font-medium">
                        {context.vessel_name ?? 'Not set'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">Rank: </span>
                    <span className="font-medium">
                        {context.rank_name ?? 'Not set'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">Actual join: </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.actual_join_at)}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Planned sign-off:{' '}
                    </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.planned_signoff_at)}
                    </span>
                </div>
                {context.days_onboard !== null ? (
                    <div>
                        <span className="text-muted-foreground">
                            Days onboard:{' '}
                        </span>
                        <span className="font-medium">
                            {formatDaysOnboard(context.days_onboard)}
                        </span>
                    </div>
                ) : null}
            </div>

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    inputRef={firstFieldRef}
                    min={
                        context.actual_join_at
                            ? `${context.actual_join_at}T00:00`
                            : undefined
                    }
                />
            ) : null}

            {beforeJoin ? (
                <p className="text-sm text-destructive">
                    The actual disembarkation cannot be before the employee
                    joined the vessel.
                </p>
            ) : null}

            {config.nextPhaseOptions && config.nextPhaseLabel ? (
                <MovementNextPhaseChoice
                    id="movement-next-phase"
                    label={config.nextPhaseLabel}
                    value={form.data.next_phase}
                    options={config.nextPhaseOptions}
                    onChange={(value) => form.setData('next_phase', value)}
                    error={form.errors.next_phase}
                />
            ) : null}
        </div>
    );
}
