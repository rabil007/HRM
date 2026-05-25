import { X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    EXPIRY_FILTER_LABELS
    
} from '@/features/organization/documents/document-expiry';
import type {ExpiryFilter} from '@/features/organization/documents/document-expiry';

export function DocumentsActiveFilters({
    expiryFilter,
    search,
    onClearExpiry,
    onClearSearch,
}: {
    expiryFilter: ExpiryFilter;
    search?: string;
    onClearExpiry?: () => void;
    onClearSearch?: () => void;
}) {
    const hasExpiryFilter = expiryFilter !== 'all';
    const hasSearch = (search?.trim() ?? '') !== '' && onClearSearch !== undefined;

    if (!hasExpiryFilter && !hasSearch) {
        return null;
    }

    return (
        <div className="mb-4 flex flex-wrap items-center gap-2">
            <span className="text-xs font-medium text-muted-foreground/80">Active filters</span>

            {hasExpiryFilter ? (
                <Badge variant="outline" className="gap-1 border-primary/25 bg-primary/5 pr-1 pl-2.5 font-normal">
                    {EXPIRY_FILTER_LABELS[expiryFilter]}
                    {onClearExpiry ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-5 w-5 rounded-full hover:bg-primary/10"
                            onClick={onClearExpiry}
                            aria-label="Clear expiry filter"
                        >
                            <X className="h-3 w-3" />
                        </Button>
                    ) : null}
                </Badge>
            ) : null}

            {hasSearch ? (
                <Badge variant="outline" className="max-w-xs gap-1 truncate border-white/10 pr-1 pl-2.5 font-normal">
                    <span className="truncate">Search: {search}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-5 w-5 shrink-0 rounded-full hover:bg-muted/60"
                        onClick={onClearSearch}
                        aria-label="Clear search"
                    >
                        <X className="h-3 w-3" />
                    </Button>
                </Badge>
            ) : null}
        </div>
    );
}
