import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import type { CrewReliefFields } from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

const RISK_STYLES: Record<string, string> = {
    none: 'border-muted-foreground/30 bg-muted/40 text-muted-foreground',
    warning:
        'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200',
    critical: 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300',
};

const STATUS_STYLES: Record<string, string> = {
    no_relief: 'border-muted-foreground/30 bg-muted/40 text-muted-foreground',
    relief_planned:
        'border-sky-500/40 bg-sky-500/10 text-sky-800 dark:text-sky-200',
    assignment_created: 'border-primary/35 bg-primary/10 text-primary',
    mobilising:
        'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200',
    ready_to_join:
        'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
    relief_onboard:
        'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
};

type Props = Pick<
    CrewReliefFields,
    | 'relief_status'
    | 'relief_status_label'
    | 'relief_risk'
    | 'relief_risk_label'
    | 'relief_employee'
>;

export function CrewReliefReadinessBadge({
    relief_status: status,
    relief_status_label: statusLabel,
    relief_risk: risk,
    relief_risk_label: riskLabel,
    relief_employee: employee,
}: Props): ReactElement {
    const resolvedStatusLabel = statusLabel ?? 'No Relief';
    const resolvedRiskLabel = riskLabel ?? 'None';
    const employeeName = employee?.name ?? 'Unassigned';
    const summary = [
        `Relief: ${resolvedStatusLabel}`,
        `Risk: ${resolvedRiskLabel}`,
        `Relief crew: ${employeeName}`,
    ].join('. ');

    return (
        <div
            className="flex min-w-0 flex-col gap-1"
            role="group"
            aria-label={summary}
        >
            <Badge
                variant="outline"
                className={cn(
                    'w-fit max-w-full font-medium',
                    STATUS_STYLES[status ?? 'no_relief'] ??
                        'border-border bg-muted/30 text-foreground',
                )}
            >
                <span className="truncate">{resolvedStatusLabel}</span>
            </Badge>
            {employee ? (
                <p className="truncate text-[11px] text-muted-foreground">
                    <span className="sr-only">Relief employee: </span>
                    {employee.name}
                    {employee.employee_no ? (
                        <span className="font-mono text-muted-foreground/75">
                            {' '}
                            ({employee.employee_no})
                        </span>
                    ) : null}
                </p>
            ) : (
                <p className="truncate text-[11px] text-muted-foreground/70">
                    No relief crew
                </p>
            )}
            {risk && risk !== 'none' ? (
                <Badge
                    variant="outline"
                    className={cn(
                        'w-fit max-w-full text-[10px] font-medium',
                        RISK_STYLES[risk] ?? RISK_STYLES.none,
                    )}
                >
                    <span className="sr-only">Relief risk: </span>
                    {resolvedRiskLabel}
                </Badge>
            ) : null}
        </div>
    );
}
