import type { ReactElement } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

/**
 * Placeholder for a chart that is still waiting on data or on its lazily
 * loaded Recharts bundle.
 */
export function ChartSkeleton({
    className,
}: {
    className?: string;
}): ReactElement {
    return (
        <div
            className={cn('space-y-3', className)}
            role="status"
            aria-live="polite"
            aria-label="Loading chart"
        >
            <Skeleton className="h-4 w-1/3" />
            <Skeleton className="h-48 w-full rounded-xl" />
        </div>
    );
}
