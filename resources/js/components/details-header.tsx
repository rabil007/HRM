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
    title: string;
    description?: string;
    backHref: string;
    backLabel: string;
    actions?: ReactNode;
}) {
    return (
        <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <div className="space-y-1.5">
                <div className="flex items-center gap-2 mb-1">
                    <span className="flex h-2 w-2 rounded-full bg-primary animate-pulse" />
                    <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">{kicker}</span>
                </div>
                <h1 className="text-4xl font-extrabold tracking-tight bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-transparent">
                    {title}
                </h1>
                {description ? <p className="text-sm text-muted-foreground/80 font-medium">{description}</p> : null}
            </div>

            <div className="flex items-center gap-3">
                <Button
                    variant="outline"
                    className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 h-12 px-6"
                    asChild
                >
                    <a href={backHref}>{backLabel}</a>
                </Button>
                {actions}
            </div>
        </div>
    );
}

