import type { ReactElement } from 'react';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import type { CrewMovementContext } from '../types';

function ContextRow({
    label,
    value,
}: {
    label: string;
    value: string | null | undefined;
}): ReactElement | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-x-2 text-sm">
            <span className="text-muted-foreground">{label}:</span>
            <span className="font-medium text-foreground">{value}</span>
        </div>
    );
}

export function MovementContextCard({
    context,
}: {
    context: CrewMovementContext;
}): ReactElement {
    const employeeLine = [context.employee_name, context.employee_no]
        .filter(Boolean)
        .join(' · ');

    const phaseLine =
        context.current_phase_code && context.current_phase_label
            ? `${context.current_phase_code.toUpperCase()} · ${context.current_phase_label}`
            : (context.current_phase_label ??
              context.current_phase_code?.toUpperCase() ??
              null);

    const startedDisplay = context.current_phase_started_at
        ? formatDisplayDateTime12h(context.current_phase_started_at)
        : null;

    return (
        <div className="space-y-1.5 rounded-lg border bg-muted/30 p-3">
            <div className="text-sm font-semibold text-foreground">
                {context.assignment_no}
            </div>
            {employeeLine ? (
                <div className="text-sm text-foreground">{employeeLine}</div>
            ) : null}
            <div className="mt-1 space-y-1">
                <ContextRow label="Current phase" value={phaseLine} />
                <ContextRow label="Started" value={startedDisplay} />
                <ContextRow label="Vessel" value={context.vessel_name} />
                <ContextRow label="Rank" value={context.rank_name} />
            </div>
            <p className="pt-1 text-xs text-muted-foreground">
                Times are recorded in {context.company_timezone}
            </p>
        </div>
    );
}
