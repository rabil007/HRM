import type { ReactNode } from 'react';

export function EmptyState({
    title = 'No results found.',
    description,
    action,
    icon,
    children,
}: {
    title?: string;
    description?: string;
    action?: ReactNode;
    icon?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="rounded-xl border border-border/80 bg-muted/30 dark:border-white/5 dark:bg-white/5 backdrop-blur-xl p-10 text-center">
            {icon}
            <div className="text-sm font-semibold text-foreground/90">{title}</div>
            {description ? <div className="mt-1 text-sm text-muted-foreground/80">{description}</div> : null}
            {children ? <div className="mt-3 text-sm text-muted-foreground/80">{children}</div> : null}
            {action ? <div className="mt-5 flex justify-center">{action}</div> : null}
        </div>
    );
}

