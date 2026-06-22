import { cn } from '@/lib/utils';
import type { PayrollCategory } from '../types';

type CategoryFilterValue = '' | PayrollCategory;

const FILTER_OPTIONS: Array<{
    value: CategoryFilterValue;
    label: string;
    activeClass: string;
}> = [
    {
        value: '',
        label: 'All',
        activeClass: 'border-primary/30 bg-primary/10 text-primary ring-1 ring-primary/20',
    },
    {
        value: 'crew',
        label: 'Crew',
        activeClass:
            'border-sky-500/30 bg-sky-500/10 text-sky-700 ring-1 ring-sky-500/20 dark:text-sky-200',
    },
    {
        value: 'office',
        label: 'Office',
        activeClass:
            'border-violet-500/30 bg-violet-500/10 text-violet-700 ring-1 ring-violet-500/20 dark:text-violet-200',
    },
];

const idleClass =
    'border-border/60 bg-muted/20 text-muted-foreground hover:border-border hover:bg-muted/40 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/8';

export function PayrollCategoryFilterChips({
    value,
    onChange,
    className,
}: {
    value: CategoryFilterValue;
    onChange: (category: CategoryFilterValue) => void;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-wrap items-center gap-1.5', className)}>
            {FILTER_OPTIONS.map((option) => {
                const isActive = value === option.value;

                return (
                    <button
                        key={option.value || 'all'}
                        type="button"
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'rounded-xl border px-3 py-1.5 text-xs font-semibold transition-all',
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
