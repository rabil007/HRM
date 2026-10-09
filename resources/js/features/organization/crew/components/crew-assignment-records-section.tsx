import type { ReactElement, ReactNode } from 'react';

export function CrewAssignmentRecordsSection({
    children,
}: {
    children: ReactNode;
}): ReactElement {
    return (
        <section
            aria-label="Assignment records and history"
            className="space-y-4"
        >
            <div>
                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                    Assignment Records & History
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                    Relationships, accommodation, corrections, remarks, and
                    audit history
                </p>
            </div>
            <div className="space-y-4">{children}</div>
        </section>
    );
}
