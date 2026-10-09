import { Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import {
    CrewAssignmentCollapsibleRecord,
    recordStatusBadge,
} from '@/features/organization/crew/components/crew-assignment-collapsible-record';
import { CrewMovementCorrectionStatusBadge } from '@/features/organization/crew-movement-corrections/components/crew-movement-correction-status-badge';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import { show as showCorrection } from '@/routes/organization/crew-movement-corrections';
import type { CorrectionsSummary } from '../types';

export function CorrectionHistoryCard({
    corrections,
    defaultOpen,
}: {
    corrections: CorrectionsSummary;
    defaultOpen?: boolean;
}): ReactElement {
    const pendingCount = corrections.pending_count;
    const openByDefault = defaultOpen ?? pendingCount > 0;
    const historyCount = corrections.history.length;

    return (
        <CrewAssignmentCollapsibleRecord
            title="Correction History"
            summary={
                historyCount === 0
                    ? 'No correction requests recorded yet'
                    : `${historyCount} request${historyCount === 1 ? '' : 's'} in history`
            }
            badges={
                pendingCount > 0
                    ? recordStatusBadge(`${pendingCount} pending`, 'warning')
                    : corrections.approved_count > 0
                      ? recordStatusBadge(
                            `${corrections.approved_count} approved`,
                            'secondary',
                        )
                      : undefined
            }
            defaultOpen={openByDefault}
            contentClassName="pt-4"
            data-slot="assignment-correction-history"
        >
            {corrections.history.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No correction requests recorded for this assignment yet.
                </p>
            ) : (
                <ul className="space-y-3">
                    {corrections.history.map((correction) => (
                        <li
                            key={correction.id}
                            className="border-b border-border/50 pb-3 last:border-b-0 last:pb-0"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <Link
                                    href={showCorrection.url(correction.id)}
                                    className="text-sm font-medium text-primary hover:underline"
                                >
                                    {correction.phase
                                        ? `${correction.phase.phase_code.toUpperCase()} · ${correction.phase.phase_label}`
                                        : `Correction #${correction.id}`}
                                </Link>
                                <CrewMovementCorrectionStatusBadge
                                    status={correction.status}
                                    label={correction.status_label}
                                />
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Requested by {correction.requester?.name ?? '—'}{' '}
                                on{' '}
                                {formatDisplayDateTime12h(
                                    correction.requested_at,
                                )}
                            </p>
                            {correction.decided_at ? (
                                <p className="text-xs text-muted-foreground">
                                    Decided by{' '}
                                    {correction.decision_maker?.name ?? '—'} on{' '}
                                    {formatDisplayDateTime12h(
                                        correction.decided_at,
                                    )}
                                </p>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </CrewAssignmentCollapsibleRecord>
    );
}
