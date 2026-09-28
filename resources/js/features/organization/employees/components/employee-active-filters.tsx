import { X } from 'lucide-react';
import { useMemo } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { buildEmployeeActiveFilterChips } from '../lib/employee-active-filters';
import type { EmployeeFilterOptions } from '../lib/employee-active-filters';
import type { EmployeeFilters } from '../lib/employee-client-project-filter';

export function EmployeeActiveFilters({
    filters,
    search,
    options,
    onClearSearch,
    onChange,
    className,
}: {
    filters: EmployeeFilters;
    search?: string;
    options: EmployeeFilterOptions;
    onClearSearch?: () => void;
    onChange: (next: EmployeeFilters) => void;
    className?: string;
}) {
    const chips = useMemo(
        () =>
            buildEmployeeActiveFilterChips({
                filters,
                searchInput: search,
                options,
                onClearSearch,
                onApplyFilters: onChange,
            }),
        [filters, onChange, onClearSearch, options, search],
    );

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
        </div>
    );
}
