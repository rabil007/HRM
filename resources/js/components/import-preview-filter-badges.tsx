import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import type {
    ImportPreviewFilterBadgeItem,
    ImportPreviewRowFilter,
} from '@/lib/import-preview-row-filter';
import { toggleImportPreviewFilter } from '@/lib/import-preview-row-filter';
import { cn } from '@/lib/utils';

export function ImportPreviewFilterBadges({
    badges,
    value,
    onChange,
}: {
    badges: ImportPreviewFilterBadgeItem[];
    value: ImportPreviewRowFilter;
    onChange: (value: ImportPreviewRowFilter) => void;
}): ReactElement {
    return (
        <div className="space-y-1">
            <div
                className="flex flex-wrap gap-2"
                role="group"
                aria-label="Filter preview rows"
            >
                {badges.map((badge) => {
                    if (badge.hideWhenZero && badge.count === 0) {
                        return null;
                    }

                    const isActive = value === badge.key;

                    return (
                        <Badge
                            key={badge.key}
                            asChild
                            variant={badge.variant ?? 'secondary'}
                            className={cn(
                                'cursor-pointer hover:opacity-90',
                                isActive &&
                                    'ring-2 ring-ring/70 ring-offset-2 ring-offset-background',
                                badge.className,
                            )}
                        >
                            <button
                                type="button"
                                aria-pressed={isActive}
                                aria-label={`Filter to ${badge.count} ${badge.label}`}
                                onClick={() =>
                                    onChange(
                                        toggleImportPreviewFilter(
                                            value,
                                            badge.key,
                                        ),
                                    )
                                }
                            >
                                {badge.count} {badge.label}
                            </button>
                        </Badge>
                    );
                })}
            </div>
            <p className="text-xs text-muted-foreground">
                Click a count to filter the table.
            </p>
        </div>
    );
}

export function ImportPreviewFilterStatus({
    visibleCount,
    totalCount,
    filter,
    searchQuery,
}: {
    visibleCount: number;
    totalCount: number;
    filter: ImportPreviewRowFilter;
    searchQuery: string;
}): ReactElement | null {
    const hasSearch = searchQuery.trim() !== '';

    if (filter === 'all' && (!hasSearch || visibleCount === totalCount)) {
        return null;
    }

    return (
        <p className="text-xs text-muted-foreground">
            Showing {visibleCount} of {totalCount} rows
        </p>
    );
}
