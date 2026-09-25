import type { ReactElement } from 'react';
import type { CrewPlanningStartContext } from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';

function ReadOnlyField({
    label,
    value,
}: {
    label: string;
    value: string;
}): ReactElement {
    return (
        <div className="space-y-1">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="text-sm font-medium">{value}</p>
        </div>
    );
}

export function PlanningStartAuthoritativeFields({
    context,
}: {
    context: CrewPlanningStartContext;
}): ReactElement {
    return (
        <section className="space-y-4">
            <div>
                <h2 className="text-sm font-semibold tracking-tight">
                    From Crew Planning
                </h2>
                <p className="text-xs text-muted-foreground">
                    These values come from the planning record. Update Crew
                    Planning if any detail is wrong before starting
                    mobilisation.
                </p>
            </div>

            <div className="grid gap-4 rounded-xl border border-border/60 bg-muted/10 p-4 md:grid-cols-2">
                <ReadOnlyField
                    label="Crew Member"
                    value={context.employee_name ?? 'Select on form'}
                />
                <ReadOnlyField
                    label="Rank"
                    value={context.rank_name ?? 'Select on form'}
                />
                <ReadOnlyField
                    label="Client"
                    value={context.client_name ?? 'Not assigned'}
                />
                <ReadOnlyField
                    label="Vessel"
                    value={context.vessel_name ?? 'Select on form'}
                />
                <ReadOnlyField
                    label="Expected Vessel Join"
                    value={
                        context.planned_join_at
                            ? formatDisplayDate(context.planned_join_at)
                            : 'Select on form'
                    }
                />
            </div>
        </section>
    );
}
