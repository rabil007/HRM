import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function DetailsHeader({
    kicker = 'Organization Management',
    title,
    description,
    backHref,
    backLabel,
    actions,
    avatar,
    badges,
    titleClassName,
    className,
}: {
    kicker?: string;
    title: ReactNode;
    description?: ReactNode;
    backHref: string;
    backLabel: string;
    actions?: ReactNode;
    avatar?: ReactNode;
    badges?: ReactNode;
    titleClassName?: string;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'mb-8 flex flex-col gap-6 md:flex-row md:flex-wrap md:items-end md:justify-between',
                className,
            )}
        >
            <div className="flex min-w-0 flex-1 items-start gap-4">
                {avatar ? (
                    <div className="shrink-0 pt-0.5">{avatar}</div>
                ) : null}
                <div className="min-w-0 flex-1 space-y-1.5">
                    <div className="mb-1 flex items-center gap-2">
                        <span className="flex h-2 w-2 animate-pulse rounded-full bg-primary" />
                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                            {kicker}
                        </span>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1
                            className={cn(
                                'bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent',
                                titleClassName,
                            )}
                        >
                            {title}
                        </h1>
                        {badges ? (
                            <div className="flex flex-wrap items-center gap-2">
                                {badges}
                            </div>
                        ) : null}
                    </div>
                    {description ? (
                        <div className="text-sm font-medium text-muted-foreground/80">
                            {description}
                        </div>
                    ) : null}
                </div>
            </div>

            <div className="flex shrink-0 flex-wrap items-center gap-2 md:justify-end">
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
