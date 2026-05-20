import type { ReactElement } from 'react';

export function EmployeeTabSkeleton(): ReactElement {
    return (
        <div className="mt-6 animate-pulse space-y-4 rounded-2xl border border-white/[0.08] bg-white/[0.03] p-6">
            <div className="h-4 w-40 rounded bg-white/10" />
            <div className="h-32 rounded-xl bg-white/[0.05]" />
        </div>
    );
}
