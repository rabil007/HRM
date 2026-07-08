import { ArrowRight, Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function DocumentsBulkToolbar({
    count,
    itemLabel,
    onClear,
    actions,
    selectAll,
    selectAllMatching,
    className,
}: {
    count: number;
    itemLabel: string;
    onClear: () => void;
    actions: ReactNode;
    selectAll?: ReactNode;
    selectAllMatching?: {
        total: number;
        onSelect: () => void;
        loading?: boolean;
    };
    className?: string;
}) {
    if (count === 0) {
        return null;
    }

    const label =
        count === 1
            ? `1 ${itemLabel.replace(/s$/, '')}`
            : `${count} ${itemLabel}`;

    return (
        <div
            className={cn(
                'mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-2.5',
                'animate-in duration-200 fade-in slide-in-from-top-1',
                className,
            )}
        >
            {selectAll}
            <span className="text-sm font-medium text-foreground">
                {label} selected
            </span>
            {selectAllMatching ? (
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    className="h-8 rounded-full px-3 text-xs"
                    onClick={selectAllMatching.onSelect}
                    disabled={selectAllMatching.loading}
                >
                    {selectAllMatching.loading ? (
                        <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                    ) : (
                        <ArrowRight className="mr-1.5 h-3.5 w-3.5" />
                    )}
                    Select all {selectAllMatching.total}
                </Button>
            ) : null}
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 rounded-lg px-2.5 text-xs text-muted-foreground hover:text-foreground"
                onClick={onClear}
            >
                Clear
            </Button>
            <div className="ml-auto flex flex-wrap items-center gap-2">
                {actions}
            </div>
        </div>
    );
}
