import { cn } from '@/lib/utils';
import type { LeaveRequestStatus } from '../types';

type StatusFilterValue = '' | LeaveRequestStatus;

const STATUS_FILTER_OPTIONS: Array<{
    value: StatusFilterValue;
    label: string;
    activeClass: string;
}> = [
    {
        value: '',
        label: 'All',
        activeClass: 'border-primary/30 bg-primary/10 text-primary ring-1 ring-primary/20',
    },
    {
        value: 'pending',
        label: 'Pending',
        activeClass: 'border-amber-500/30 bg-amber-500/10 text-amber-700 ring-1 ring-amber-500/20 dark:text-amber-200',
    },
    {
        value: 'approved',
        label: 'Approved',
        activeClass: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 ring-1 ring-emerald-500/20 dark:text-emerald-200',
    },
    {
        value: 'rejected',
        label: 'Rejected',
        activeClass: 'border-red-500/30 bg-red-500/10 text-red-700 ring-1 ring-red-500/20 dark:text-red-200',
    },
    {
        value: 'cancelled',
        label: 'Cancelled',
        activeClass: 'border-border bg-muted/50 text-muted-foreground ring-1 ring-border/60 dark:border-zinc-500/30 dark:bg-zinc-500/10 dark:text-zinc-200',
    },
];

const idleClass =
    'border-border/60 bg-muted/20 text-muted-foreground hover:border-border hover:bg-muted/40 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/8';

export function LeaveRequestStatusFilterChips({
    value,
    onChange,
    className,
}: {
    value: StatusFilterValue;
    onChange: (status: StatusFilterValue) => void;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-wrap items-center gap-1.5 sm:gap-2', className)}>
            {STATUS_FILTER_OPTIONS.map((option) => {
                const isActive = value === option.value;

                return (
                    <button
                        key={option.value || 'all'}
                        type="button"
                        onClick={() => onChange(isActive && option.value !== '' ? '' : option.value)}
                        className={cn(
                            'inline-flex h-12 shrink-0 items-center rounded-xl border px-3 text-xs font-semibold transition-colors sm:px-3.5',
                            isActive ? option.activeClass : idleClass,
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
