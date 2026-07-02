import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function PageHeader({
    kicker = 'Organization Management',
    title,
    description,
    right,
    className,
}: {
    kicker?: string;
    title: string;
    description?: string;
    right?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between',
                className,
            )}
        >
            <div className="space-y-1.5">
                <div className="mb-1 flex items-center gap-2">
                    <span className="flex h-2 w-2 animate-pulse rounded-full bg-primary" />
                    <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                        {kicker}
                    </span>
                </div>
                <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">
                    {title}
                </h1>
                {description ? (
                    <p className="text-sm font-medium text-muted-foreground/80">
                        {description}
                    </p>
                ) : null}
            </div>
            {right ? (
                <div className="flex items-center gap-3">{right}</div>
            ) : null}
        </div>
    );
}
