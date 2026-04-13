import type { ReactNode } from 'react';

export function EmptyState({
    title = 'No results found.',
    description,
    action,
}: {
    title?: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="rounded-xl border border-white/5 bg-white/5 backdrop-blur-xl p-10 text-center">
            <div className="text-sm font-semibold text-foreground/90">{title}</div>
            {description ? <div className="mt-1 text-sm text-muted-foreground/80">{description}</div> : null}
            {action ? <div className="mt-5 flex justify-center">{action}</div> : null}
        </div>
    );
}

