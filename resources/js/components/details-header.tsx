import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

export function DetailsHeader({
    kicker = 'Organization Management',
    title,
    description,
    backHref,
    backLabel,
    actions,
}: {
    kicker?: string;
    title: ReactNode;
    description?: string;
    backHref: string;
    backLabel: string;
    actions?: ReactNode;
}) {
    return (
        <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
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

            <div className="flex items-center gap-3">
                <Button
                    variant="outline"
                    className="h-12 rounded-xl border-input bg-background/50 px-6 hover:bg-muted dark:border-white/5 dark:bg-white/5 dark:hover:bg-white/10"
                    asChild
                >
                    <a href={backHref}>{backLabel}</a>
                </Button>
                {actions}
            </div>
        </div>
    );
}
