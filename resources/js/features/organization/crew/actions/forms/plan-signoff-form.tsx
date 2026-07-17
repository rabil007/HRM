import type { ReactElement, RefObject } from 'react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDisplayDate } from '@/lib/format-date';
import { formatDaysOnboard } from '../../format-days-in-phase';
import type { MovementActionFormProps } from './movement-form-shared';

export function PlanSignoffForm({
    form,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    useEffect(() => {
        firstFieldRef?.current?.focus();
    }, [firstFieldRef]);

    const signoffBeforeJoin =
        form.data.planned_signoff_at &&
        context.actual_join_at &&
        form.data.planned_signoff_at < context.actual_join_at;

    return (
        <div className="space-y-4">
            <div className="space-y-1 rounded-lg border bg-muted/20 p-3 text-sm">
                <div>
                    <span className="text-muted-foreground">Actual join: </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.actual_join_at)}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Current planned sign-off:{' '}
                    </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.planned_signoff_at)}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">Vessel: </span>
                    <span className="font-medium">
                        {context.vessel_name ?? 'Not set'}
                    </span>
                </div>
                {context.days_onboard !== null ? (
                    <div>
                        <span className="text-muted-foreground">
                            Time onboard:{' '}
                        </span>
                        <span className="font-medium">
                            {formatDaysOnboard(context.days_onboard)}
                        </span>
                    </div>
                ) : null}
            </div>

            <div className="space-y-2">
                <Label htmlFor="movement-plan-signoff">
                    New planned sign-off{' '}
                    <span className="text-destructive">*</span>
                </Label>
                <Input
                    id="movement-plan-signoff"
                    ref={
                        firstFieldRef as
                            | RefObject<HTMLInputElement | null>
                            | undefined
                    }
                    type="date"
                    value={form.data.planned_signoff_at}
                    min={context.actual_join_at ?? undefined}
                    onChange={(event) =>
                        form.setData('planned_signoff_at', event.target.value)
                    }
                    required
                    aria-required="true"
                />
                {signoffBeforeJoin ? (
                    <p className="text-sm text-destructive">
                        Planned sign-off cannot be before actual join.
                    </p>
                ) : null}
                <InputError message={form.errors.planned_signoff_at} />
            </div>
        </div>
    );
}
