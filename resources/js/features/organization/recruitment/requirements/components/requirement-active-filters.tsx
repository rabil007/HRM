import { X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { RequirementFilters } from '@/types/recruitment';
import { buildRequirementActiveFilterChips } from '../lib/requirement-active-filters';
import type { RequirementFilterOptions } from '../lib/requirement-active-filters';

type Props = {
    filters: RequirementFilters;
    options: RequirementFilterOptions;
    searchInput?: string;
    onChange: (changes: Partial<RequirementFilters>) => void;
    onClearAll: () => void;
    className?: string;
};

export function RequirementActiveFilters({
    filters,
    options,
    searchInput,
    onChange,
    onClearAll,
    className,
}: Props) {
    const chips = buildRequirementActiveFilterChips(
        filters,
        options,
        searchInput,
    );

    if (chips.length === 0) {
        return null;
    }

    return (
        <div
            className={cn('flex flex-wrap items-center gap-2', className)}
            aria-label="Active filters"
        >
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
                        onClick={() => onChange(chip.clear)}
                        aria-label={chip.ariaLabel}
                    >
                        <X className="h-3 w-3" />
                    </Button>
                </Badge>
            ))}

            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
                onClick={onClearAll}
            >
                Clear all
            </Button>
        </div>
    );
}
