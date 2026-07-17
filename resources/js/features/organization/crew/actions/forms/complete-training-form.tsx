import type { ReactElement } from 'react';
import { formatDisplayDate, formatDisplayDateTime12h } from '@/lib/format-date';
import { formatDaysInTraining } from '../../format-days-in-phase';
import { MovementNextPhaseChoice } from '../movement-next-phase-choice';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function CompleteTrainingForm({
    form,
    config,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const hasSummary =
        context.training_provider ||
        context.training_course ||
        context.training_started_at ||
        context.training_expected_completion_at ||
        context.days_in_training !== null;

    return (
        <div className="space-y-4">
            {hasSummary ? (
                <div className="space-y-1 rounded-lg border bg-muted/20 p-3 text-sm">
                    {context.training_provider ? (
                        <div>
                            <span className="text-muted-foreground">
                                Provider:{' '}
                            </span>
                            <span className="font-medium">
                                {context.training_provider}
                            </span>
                        </div>
                    ) : null}
                    {context.training_course ? (
                        <div>
                            <span className="text-muted-foreground">
                                Course:{' '}
                            </span>
                            <span className="font-medium">
                                {context.training_course}
                            </span>
                        </div>
                    ) : null}
                    {context.training_started_at ? (
                        <div>
                            <span className="text-muted-foreground">
                                Training started:{' '}
                            </span>
                            <span className="font-medium">
                                {formatDisplayDateTime12h(
                                    context.training_started_at,
                                )}
                            </span>
                        </div>
                    ) : null}
                    {context.training_expected_completion_at ? (
                        <div>
                            <span className="text-muted-foreground">
                                Expected completion:{' '}
                            </span>
                            <span className="font-medium">
                                {formatDisplayDate(
                                    context.training_expected_completion_at,
                                )}
                            </span>
                        </div>
                    ) : null}
                    {context.days_in_training !== null ? (
                        <div>
                            <span className="text-muted-foreground">
                                Time in training:{' '}
                            </span>
                            <span className="font-medium">
                                {formatDaysInTraining(context.days_in_training)}
                            </span>
                        </div>
                    ) : null}
                </div>
            ) : null}

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    inputRef={firstFieldRef}
                />
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
