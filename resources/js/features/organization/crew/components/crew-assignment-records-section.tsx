import type { ReactElement, ReactNode } from 'react';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { RecentActivityCard } from '@/components/recent-activity-card';
import {
    CrewAssignmentCollapsibleRecord,
    recordStatusBadge,
} from '@/features/organization/crew/components/crew-assignment-collapsible-record';

export function CrewAssignmentRecordsSection({
    children,
}: {
    children: ReactNode;
}): ReactElement {
    return (
        <section
            aria-label="Assignment records and history"
            className="space-y-4"
            data-slot="assignment-records-section"
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

export function CrewAssignmentRemarksRecord({
    remarks,
    defaultOpen = false,
}: {
    remarks: string;
    defaultOpen?: boolean;
}): ReactElement {
    return (
        <CrewAssignmentCollapsibleRecord
            title="Remarks"
            titleClassName="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
            headerClassName="border-b border-border/50 dark:border-white/5"
            summary="Assignment remarks"
            defaultOpen={defaultOpen}
            contentClassName="pt-4"
            data-slot="assignment-remarks"
        >
            <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                {remarks}
            </p>
        </CrewAssignmentCollapsibleRecord>
    );
}

export function CrewAssignmentAuditRecord({
    items,
    canViewAudit,
    defaultOpen = false,
}: {
    items: RecentActivityItem[];
    canViewAudit: boolean;
    defaultOpen?: boolean;
}): ReactElement | null {
    if (!canViewAudit) {
        return null;
    }

    if (items.length === 0) {
        return (
            <CrewAssignmentCollapsibleRecord
                title="Audit History"
                summary="No audit history recorded yet"
                defaultOpen={defaultOpen}
                contentClassName="pt-4"
                data-slot="assignment-audit-history"
            >
                <p className="text-sm text-muted-foreground">
                    No audit history recorded for this assignment yet.
                </p>
            </CrewAssignmentCollapsibleRecord>
        );
    }

    return (
        <CrewAssignmentCollapsibleRecord
            title="Audit History"
            summary={`${items.length} recent change${items.length === 1 ? '' : 's'}`}
            badges={recordStatusBadge(
                `${items.length} event${items.length === 1 ? '' : 's'}`,
                'secondary',
            )}
            defaultOpen={defaultOpen}
            contentClassName="pt-4"
            data-slot="assignment-audit-history"
        >
            <div className="[&_[data-slot=card-header]]:hidden [&_[data-slot=card]]:mt-0 [&_[data-slot=card]]:gap-0 [&_[data-slot=card]]:border-0 [&_[data-slot=card]]:bg-transparent [&_[data-slot=card]]:py-0 [&_[data-slot=card]]:shadow-none dark:[&_[data-slot=card]]:bg-transparent">
                <RecentActivityCard
                    items={items}
                    description="Latest changes for this crew assignment."
                />
            </div>
        </CrewAssignmentCollapsibleRecord>
    );
}
