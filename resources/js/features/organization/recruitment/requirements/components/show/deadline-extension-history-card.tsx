import { History } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { RequirementDeadlineExtension } from '@/types/recruitment';

type Props = {
    extensions: RequirementDeadlineExtension[];
};

function historyLabel(extension: RequirementDeadlineExtension): string {
    if (extension.initiator === 'requester') {
        return 'Deadline extended by requester';
    }

    if (extension.status === 'pending') {
        return 'Deadline extension requested';
    }

    if (extension.status === 'approved') {
        return 'Deadline extension approved';
    }

    if (extension.status === 'rejected') {
        return 'Deadline extension rejected';
    }

    return 'Deadline extension cancelled';
}

export function DeadlineExtensionHistoryCard({ extensions }: Props) {
    const items = extensions.filter((item) => item.status !== 'pending');

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
                    Deadline history
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 pt-0">
                {items.map((extension) => (
                    <div
                        key={extension.id}
                        className="rounded-lg border border-border/60 bg-muted/20 p-3 text-sm"
                    >
                        <p className="text-xs text-muted-foreground">
                            {extension.decided_at_formatted ??
                                extension.requested_at_formatted}
                        </p>
                        <p className="mt-1 font-medium">
                            {historyLabel(extension)}
                        </p>
                        <p className="mt-1 text-xs">
                            {extension.status === 'rejected'
                                ? `Requested deadline: ${extension.requested_deadline_formatted}. Current deadline remains: ${extension.old_deadline_formatted}.`
                                : `${extension.old_deadline_formatted} → ${extension.requested_deadline_formatted}`}
                        </p>
                        {extension.initiator === 'recruiter' &&
                        extension.requested_by_name ? (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Requested by {extension.requested_by_name}
                            </p>
                        ) : null}
                        {extension.decided_by_name &&
                        extension.status !== 'pending' ? (
                            <p className="text-xs text-muted-foreground">
                                {extension.status === 'rejected'
                                    ? 'Rejected'
                                    : extension.initiator === 'requester'
                                      ? 'Changed'
                                      : 'Approved'}{' '}
                                by {extension.decided_by_name}
                            </p>
                        ) : null}
                        {extension.reason ? (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Reason: {extension.reason}
                            </p>
                        ) : null}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
