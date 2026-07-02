import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { DataTableHead } from '@/components/data-table';
import { cn } from '@/lib/utils';

export function SortableDeploymentTableHead({
    children,
    sortKey,
    activeSort,
    direction,
    onSort,
    className,
    colSpan,
    rowSpan,
}: {
    children: ReactNode;
    sortKey: string;
    activeSort: string;
    direction: string;
    onSort: (sortKey: string) => void;
    className?: string;
    colSpan?: number;
    rowSpan?: number;
}): ReactElement {
    const isActive = activeSort === sortKey;

    return (
        <DataTableHead
            className={className}
            colSpan={colSpan}
            rowSpan={rowSpan}
        >
            <button
                type="button"
                onClick={() => onSort(sortKey)}
                className={cn(
                    'inline-flex items-center gap-1.5 text-left transition-colors hover:text-foreground',
                    isActive ? 'text-foreground' : 'text-muted-foreground',
                )}
            >
                <span>{children}</span>
                {isActive ? (
                    direction === 'asc' ? (
                        <ArrowUp className="size-3.5 shrink-0" aria-hidden />
                    ) : (
                        <ArrowDown className="size-3.5 shrink-0" aria-hidden />
                    )
                ) : (
                    <ArrowUpDown
                        className="size-3.5 shrink-0 opacity-35"
                        aria-hidden
                    />
                )}
            </button>
        </DataTableHead>
    );
}
