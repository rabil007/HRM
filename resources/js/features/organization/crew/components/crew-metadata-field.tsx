import type { ReactElement, ReactNode } from 'react';

export function CrewMetadataField({
    label,
    value,
}: {
    label: string;
    value: ReactNode;
}): ReactElement {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-border/50 px-1 py-3 last:border-b-0">
            <span className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                {label}
            </span>
            <span className="max-w-[65%] text-right text-sm font-medium text-foreground">
                {value}
            </span>
        </div>
    );
}
