import { History } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { RequirementHeadcountRevision } from '@/types/recruitment';

type Props = {
    revisions: RequirementHeadcountRevision[];
};

export function HeadcountRevisionHistoryCard({ revisions }: Props) {
    const items = revisions.filter((item) => item.status !== 'pending');

    if (items.length === 0) {
        return null;
    }

    return (
        <Card className="overflow-hidden border-border/70 shadow-xs">
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base font-semibold">
                    <History
                        className="h-4 w-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    Headcount history
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 pt-0">
                {items.map((revision) => (
                    <div
                        key={revision.id}
                        className="rounded-lg border border-border/60 bg-muted/20 p-3 text-sm"
                    >
                        <p className="text-xs text-muted-foreground">
                            {revision.requested_at_formatted}
                        </p>
                        <p className="mt-1 font-medium">
                            {revision.initiator === 'requester'
                                ? 'Headcount revision requested by'
                                : 'Revision requested by'}{' '}
                            {revision.requested_by_name ??
                                revision.initiator_label}
                        </p>
                        <div className="mt-2 space-y-1">
                            {revision.lines.map((line) => (
                                <p key={line.id} className="text-xs">
                                    {line.position_title} {line.old_headcount} →{' '}
                                    {line.requested_headcount}
                                </p>
                            ))}
                        </div>
                        {revision.reason ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                {revision.note_label}: {revision.reason}
                            </p>
                        ) : null}
                        {revision.decided_at_formatted ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                {revision.decided_at_formatted}
                            </p>
                        ) : null}
                        {revision.status === 'approved' ? (
                            <p className="text-xs font-medium">
                                Approved by{' '}
                                {revision.decided_by_name ?? 'reviewer'}
                            </p>
                        ) : null}
                        {revision.status === 'rejected' ? (
                            <div className="mt-1 space-y-1 text-xs">
                                <p className="font-medium">
                                    Headcount revision rejected
                                    {revision.decided_by_name
                                        ? ` by ${revision.decided_by_name}`
                                        : ''}
                                </p>
                                <p className="text-muted-foreground">
                                    Requested:{' '}
                                    {revision.lines
                                        .map(
                                            (line) =>
                                                `${line.position_title} ${line.old_headcount} → ${line.requested_headcount}`,
                                        )
                                        .join(', ')}
                                </p>
                                <p className="text-muted-foreground">
                                    Official headcount remained{' '}
                                    {revision.lines
                                        .map(
                                            (line) =>
                                                `${line.position_title} ${line.old_headcount}`,
                                        )
                                        .join(', ')}{' '}
                                    at the time of rejection.
                                </p>
                            </div>
                        ) : null}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
