import { Badge } from '@/components/ui/badge';
import { formatDisplayDate } from '@/lib/format-date';
import { formatTimesheetDays } from '../types';

export function OperationalDateRange({
    label,
    from,
    to,
    days,
}: {
    label: string;
    from: string | null | undefined;
    to: string | null | undefined;
    days: string | null | undefined;
}) {
    const hasDays = days !== null && days !== undefined && days !== '';
    const hasRange = Boolean(from) || Boolean(to);

    return (
        <div className="space-y-1">
            <p className="text-[10px] font-semibold tracking-wide text-muted-foreground/70 uppercase">
                {label}
            </p>
            {hasRange ? (
                <p className="font-mono text-[11px] text-foreground/90">
                    {formatDisplayDate(from)} → {formatDisplayDate(to)}
                </p>
            ) : (
                <p className="text-[11px] text-muted-foreground/60">
                    No dates set
                </p>
            )}
            {hasDays && Number(days) > 0 ? (
                <Badge
                    variant="secondary"
                    className="inline-flex w-fit items-center gap-1 rounded-md border-blue-500/20 bg-blue-500/10 px-2 py-0.5 text-[10px] font-bold text-blue-700 tabular-nums dark:text-blue-300"
                >
                    {formatTimesheetDays(days)} days
                </Badge>
            ) : null}
        </div>
    );
}
