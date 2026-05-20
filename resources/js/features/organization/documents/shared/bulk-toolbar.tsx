import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function DocumentsBulkToolbar({
    count,
    itemLabel,
    onClear,
    actions,
    selectAll,
    className,
}: {
    count: number;
    itemLabel: string;
    onClear: () => void;
    actions: ReactNode;
    selectAll?: ReactNode;
    className?: string;
}) {
    if (count === 0) {
        return null;
    }

    const label = count === 1 ? `1 ${itemLabel.replace(/s$/, '')}` : `${count} ${itemLabel}`;

    return (
        <div
            className={cn(
                'mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-2.5',
                'animate-in fade-in slide-in-from-top-1 duration-200',
                className,
            )}
        >
            {selectAll}
            <span className="text-sm font-medium text-foreground">{label} selected</span>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 rounded-lg px-2.5 text-xs text-muted-foreground hover:text-foreground"
                onClick={onClear}
            >
                Clear
            </Button>
            <div className="ml-auto flex flex-wrap items-center gap-2">{actions}</div>
        </div>
    );
}
