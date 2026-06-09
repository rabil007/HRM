import type { ReactElement } from 'react';

export function EmployeeTabSkeleton(): ReactElement {
    return (
        <div className="mt-6 animate-pulse space-y-4 rounded-2xl border border-border bg-muted/20 p-6 dark:border-white/[0.08] dark:bg-white/[0.03]">
            <div className="h-4 w-40 rounded bg-muted dark:bg-white/10" />
            <div className="h-32 rounded-xl bg-muted/60 dark:bg-white/[0.05]" />
        </div>
    );
}
