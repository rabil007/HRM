import { cn } from '@/lib/utils';
import type { BulkGenerationFilter } from '../types';

export function GenerationStatusFilter({
    generationFilter,
    onFilterChange,
    counts,
}: {
    generationFilter: BulkGenerationFilter;
    onFilterChange: (filter: BulkGenerationFilter) => void;
    counts: {
        targeted: number;
        not_generated: number;
        generated: number;
    };
}) {
    const filters: Array<{
        value: BulkGenerationFilter;
        label: string;
        count: number;
    }> = [
        { value: 'all', label: 'All', count: counts.targeted },
        { value: 'missing', label: 'Missing', count: counts.not_generated },
        { value: 'generated', label: 'Generated', count: counts.generated },
    ];

    return (
        <div className="grid w-full grid-cols-3 gap-1 rounded-xl bg-muted/60 p-1 sm:inline-grid sm:w-auto">
            {filters.map((filter) => (
                <button
                    key={filter.value}
                    type="button"
                    onClick={() => onFilterChange(filter.value)}
                    aria-pressed={generationFilter === filter.value}
                    className={cn(
                        'inline-flex min-h-9 items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-all',
                        generationFilter === filter.value
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {filter.label}
                    <span
                        className={cn(
                            'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold tabular-nums',
                            generationFilter === filter.value
                                ? filter.value === 'missing'
                                    ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400'
                                    : filter.value === 'generated'
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
