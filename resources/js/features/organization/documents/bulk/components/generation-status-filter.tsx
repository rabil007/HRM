import { cn } from '@/lib/utils';
import type { BulkDocumentCounts, ProcessLifecycleFilter } from '../types';

export function GenerationStatusFilter({
    processFilter,
    onFilterChange,
    counts,
}: {
    processFilter: ProcessLifecycleFilter;
    onFilterChange: (filter: ProcessLifecycleFilter) => void;
    counts: BulkDocumentCounts;
}) {
    const filters: Array<{
        value: ProcessLifecycleFilter;
        label: string;
        count: number;
    }> = [
        {
            value: 'all',
            label: 'All',
            count: counts.all ?? counts.targeted ?? 0,
        },
        {
            value: 'not_started',
            label: 'Not started',
            count: counts.not_started ?? counts.not_generated ?? 0,
        },
        {
            value: 'in_progress',
            label: 'In progress',
            count: counts.in_progress ?? 0,
        },
        {
            value: 'needs_attention',
            label: 'Needs attention',
            count: counts.needs_attention ?? 0,
        },
        {
            value: 'completed',
            label: 'Completed',
            count: counts.completed ?? counts.generated ?? 0,
        },
    ];

    return (
        <div className="flex w-full flex-wrap gap-1 rounded-xl bg-muted/60 p-1 sm:w-auto">
            {filters.map((filter) => (
                <button
                    key={filter.value}
                    type="button"
                    onClick={() => onFilterChange(filter.value)}
                    aria-pressed={processFilter === filter.value}
                    className={cn(
                        'inline-flex min-h-9 flex-1 items-center justify-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition-all sm:flex-initial sm:px-3 sm:text-sm',
                        processFilter === filter.value
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {filter.label}
                    <span
                        className={cn(
                            'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold tabular-nums',
                            processFilter === filter.value
                                ? filter.value === 'needs_attention'
                                    ? 'bg-rose-500/15 text-rose-700 dark:text-rose-400'
                                    : filter.value === 'in_progress'
                                      ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400'
                                      : filter.value === 'completed'
                                        ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                                        : 'bg-primary/15 text-primary'
                                : 'bg-muted/60 text-muted-foreground',
                        )}
                    >
                        {filter.count}
                    </span>
                </button>
            ))}
        </div>
    );
}
