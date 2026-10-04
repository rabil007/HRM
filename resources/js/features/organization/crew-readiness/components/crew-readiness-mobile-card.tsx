import { ChevronRight, Ship } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { CrewMobilisationReadinessBadge } from '@/features/organization/crew/components/crew-mobilisation-readiness-badge';
import { formatJoinTimeline } from '@/features/organization/crew-readiness/lib/crew-readiness-query';
import type { CrewReadinessRow } from '@/features/organization/crew-readiness/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function CrewReadinessMobileCard({
    row,
    onSelect,
}: {
    row: CrewReadinessRow;
    onSelect: (row: CrewReadinessRow) => void;
}) {
    const timeline = formatJoinTimeline(row.days_until_join, row.is_overdue);

    return (
        <div
            className="flex cursor-pointer flex-col gap-3 rounded-xl border border-border/70 bg-card p-4 text-xs shadow-xs transition-colors hover:bg-muted/30"
            onClick={() => onSelect(row)}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-foreground">
                        {row.employee.name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {row.employee.employee_no
                            ? `${row.employee.employee_no} • `
                            : ''}
                        {row.position?.title ?? 'No rank'}
                    </p>
                </div>
                <CrewMobilisationReadinessBadge
                    readiness={row.readiness}
                    compact
                />
            </div>

            <div className="flex flex-wrap items-center gap-2 border-t pt-1 text-muted-foreground">
                <span className="flex items-center gap-1 font-medium text-foreground">
                    <Ship className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    {row.vessel?.name ?? 'No vessel'}
                </span>
                <span>•</span>
                <Badge
                    variant="secondary"
                    className="h-4.5 px-1.5 py-0 text-[10px]"
                >
                    {row.source_label}
                </Badge>
                <span>•</span>
                <span className="text-[11px]">{row.stage_label}</span>
            </div>

            <div className="grid grid-cols-2 gap-2 border-t pt-2 text-[11px]">
                <div>
                    <span className="text-muted-foreground">
                        Expected Join:
                    </span>
                    <p className="mt-0.5 font-semibold text-foreground">
                        {formatDisplayDate(row.expected_join_date)}
                    </p>
                    {timeline ? (
                        <p className={cn('text-[10px]', timeline.tone)}>
                            {timeline.label}
                        </p>
                    ) : null}
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Expected Sign-Off:
                    </span>
                    <p className="mt-0.5 font-medium text-foreground">
                        {formatDisplayDate(row.expected_signoff_date)}
                    </p>
                </div>
            </div>

            {row.readiness.problems.length > 0 ? (
                <div className="space-y-1 rounded-lg bg-muted/40 p-2.5 text-[11px]">
                    <span className="block font-semibold text-foreground">
                        Requires Attention:
                    </span>
                    {row.readiness.problems.slice(0, 2).map((prob, idx) => (
                        <p
                            key={idx}
                            className={cn(
                                'truncate',
                                prob.severity === 'critical'
                                    ? 'font-medium text-red-700 dark:text-red-400'
                                    : 'text-amber-700 dark:text-amber-400',
                            )}
                        >
                            • {prob.label}: {prob.message}
                        </p>
                    ))}
                </div>
            ) : null}

            <div className="flex items-center justify-between border-t pt-1 text-[11px] text-muted-foreground">
                <span>
                    {row.readiness.checks_clear} / {row.readiness.checks_total}{' '}
                    checks clear
                </span>
                <span className="flex items-center gap-0.5 font-medium text-primary">
                    View details
                    <ChevronRight className="h-3.5 w-3.5" />
                </span>
            </div>
        </div>
    );
}
