import { X } from 'lucide-react';
import { useMemo } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { PositionFilters } from './position-filters-sheet';

type ActiveFilterChip = {
    key: string;
    label: string;
    ariaLabel: string;
    onClear: () => void;
};

export function PositionActiveFilters({
    filters,
    search,
    departmentName,
    onClearSearch,
    onChange,
    onClearAll,
    className,
}: {
    filters: PositionFilters;
    search?: string;
    departmentName?: string | null;
    onClearSearch?: () => void;
    onChange: (next: PositionFilters) => void;
    onClearAll?: () => void;
    className?: string;
}) {
    const chips = useMemo(() => {
        const next: ActiveFilterChip[] = [];
        const trimmedSearch = search?.trim() ?? '';

        if (trimmedSearch !== '' && onClearSearch) {
            next.push({
                key: 'search',
                label: `Search: ${trimmedSearch}`,
                ariaLabel: 'Clear search',
                onClear: onClearSearch,
            });
        }

        if (filters.department_id !== '') {
            next.push({
                key: 'department',
                label: `Department: ${departmentName ?? filters.department_id}`,
                ariaLabel: 'Clear department filter',
                onClear: () =>
                    onChange({
                        ...filters,
                        department_id: '',
                    }),
            });
        }

        if (filters.status !== '') {
            next.push({
                key: 'status',
                label: `Status: ${filters.status === 'active' ? 'Active' : 'Inactive'}`,
                ariaLabel: 'Clear status filter',
                onClear: () =>
                    onChange({
                        ...filters,
                        status: '',
                    }),
            });
        }

        if (filters.grade.trim() !== '') {
            next.push({
                key: 'grade',
                label: `Grade: ${filters.grade.trim()}`,
                ariaLabel: 'Clear grade filter',
                onClear: () =>
                    onChange({
                        ...filters,
                        grade: '',
                    }),
            });
        }

        return next;
    }, [departmentName, filters, onChange, onClearSearch, search]);

    if (chips.length === 0) {
        return null;
    }

    return (
        <div className={cn('flex flex-wrap items-center gap-2', className)}>
            <span className="text-xs font-medium text-muted-foreground/80">
                Active filters
            </span>

            {chips.map((chip) => (
                <Badge
                    key={chip.key}
                    variant="outline"
                    className="max-w-[calc(100vw-4rem)] gap-1 border-primary/25 bg-primary/5 pr-1 pl-2.5 font-normal sm:max-w-md dark:border-white/10"
                >
                    <span className="truncate">{chip.label}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-5 w-5 shrink-0 rounded-full hover:bg-primary/10"
                        onClick={chip.onClear}
                        aria-label={chip.ariaLabel}
                    >
                        <X className="h-3 w-3" />
                    </Button>
                </Badge>
            ))}

            {onClearAll ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
                    onClick={onClearAll}
                >
                    Clear all
                </Button>
            ) : null}
        </div>
    );
}
