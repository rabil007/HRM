import { Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CrewMovementCorrectionStatusBadge } from '@/features/organization/crew-movement-corrections/components/crew-movement-correction-status-badge';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import { show as showCorrection } from '@/routes/organization/crew-movement-corrections';
import type { CorrectionsSummary } from '../types';

export function CorrectionHistoryCard({
    corrections,
}: {
    corrections: CorrectionsSummary;
}): ReactElement {
    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="pb-3">
                <CardTitle className="text-base">Correction History</CardTitle>
            </CardHeader>
            <CardContent>
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
                                    Requested by{' '}
                                    {correction.requester?.name ?? '—'} on{' '}
                                    {formatDisplayDateTime12h(
                                        correction.requested_at,
                                    )}
                                </p>
                                {correction.decided_at ? (
                                    <p className="text-xs text-muted-foreground">
                                        Decided by{' '}
                                        {correction.decision_maker?.name ?? '—'}{' '}
                                        on{' '}
                                        {formatDisplayDateTime12h(
                                            correction.decided_at,
                                        )}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
