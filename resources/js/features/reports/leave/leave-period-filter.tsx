import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

const inputClassName =
    'h-10 w-[9.5rem] rounded-xl border-input bg-background/80 px-3 text-sm dark:border-white/5 dark:bg-white/5';

export function LeavePeriodFilter({
    from,
    to,
    onFromChange,
    onToChange,
    className,
}: {
    from: string;
    to: string;
    onFromChange: (value: string) => void;
    onToChange: (value: string) => void;
    className?: string;
}) {
    return (
        <div
            className={cn('flex flex-wrap items-end gap-2', className)}
            title="Show leave that overlaps this period."
        >
            <div className="flex min-w-0 flex-col gap-1.5">
                <label
                    htmlFor="leave-report-period-from"
                    className="text-[11px] font-medium text-muted-foreground/60"
                >
                    Leave from
                </label>
                <Input
                    id="leave-report-period-from"
                    type="date"
                    value={from}
                    onChange={(event) => onFromChange(event.target.value)}
                    className={inputClassName}
                />
            </div>
            <div className="flex min-w-0 flex-col gap-1.5">
                <label
                    htmlFor="leave-report-period-to"
                    className="text-[11px] font-medium text-muted-foreground/60"
                >
                    Leave to
                </label>
                <Input
                    id="leave-report-period-to"
                    type="date"
                    value={to}
                    onChange={(event) => onToChange(event.target.value)}
                    className={inputClassName}
                />
            </div>
        </div>
    );
}
