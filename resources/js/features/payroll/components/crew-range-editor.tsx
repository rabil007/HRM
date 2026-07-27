import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { calculateInclusiveDays } from '../lib/calculate-inclusive-days';
import { formatTimesheetDays } from '../types';

export function CrewRangeEditor({
    label,
    from,
    to,
    onFromChange,
    onToChange,
    disabled,
    activeColorClass = 'border-blue-500/20 bg-blue-500/10 text-blue-700 dark:text-blue-300',
}: {
    label: string;
    from: string;
    to: string;
    onFromChange: (value: string) => void;
    onToChange: (value: string) => void;
    disabled: boolean;
    activeColorClass?: string;
}) {
    const days = calculateInclusiveDays(from, to);
    const inputClass =
        'h-7 w-[130px] rounded-md border-border/50 bg-background/60 px-1.5 font-mono text-[11px] shadow-none transition-colors focus:bg-background disabled:cursor-not-allowed disabled:opacity-50 [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-50 hover:[&::-webkit-calendar-picker-indicator]:opacity-90 [&::-webkit-calendar-picker-indicator]:dark:invert';

    return (
        <div className="flex flex-col gap-1">
            <p className="text-[10px] font-semibold tracking-wide text-muted-foreground/70 uppercase">
                {label}
            </p>
            <div className="flex items-center gap-1">
                <Input
                    type="date"
                    value={from}
                    onChange={(e) => onFromChange(e.target.value)}
                    className={inputClass}
                    disabled={disabled}
                />
                <span className="shrink-0 text-[10px] font-bold text-muted-foreground/40">
                    →
                </span>
                <Input
                    type="date"
                    value={to}
                    onChange={(e) => onToChange(e.target.value)}
                    className={inputClass}
                    disabled={disabled}
                />
            </div>
            <Badge
                variant="secondary"
                className={cn(
                    'inline-flex w-fit items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-bold tabular-nums transition-colors',
                    days && Number(days) > 0
                        ? activeColorClass
                        : 'border-dashed border-border/60 bg-transparent text-muted-foreground/50',
                )}
            >
                {days && Number(days) > 0 ? (
                    <>{formatTimesheetDays(days)} days</>
                ) : (
                    <>No dates set</>
                )}
            </Badge>
        </div>
    );
}
