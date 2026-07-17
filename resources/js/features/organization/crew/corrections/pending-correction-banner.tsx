import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import type { ReactElement } from 'react';
import { show as showCorrection } from '@/routes/organization/crew-movement-corrections';
import type { CorrectionsSummary } from '../types';

export function PendingCorrectionBanner({
    corrections,
}: {
    corrections: CorrectionsSummary;
}): ReactElement | null {
    if (corrections.pending_count === 0) {
        return null;
    }

    return (
        <div className="mb-6 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
            <div className="flex items-center gap-2 text-sm font-semibold text-amber-700 dark:text-amber-300">
                <AlertTriangle className="size-4" aria-hidden />
                {corrections.pending_count} pending correction
                {corrections.pending_count > 1 ? 's' : ''} awaiting review
            </div>
            <div className="mt-2 space-y-1">
                {corrections.pending.map((correction) => (
                    <Link
                        key={correction.id}
                        href={showCorrection.url(correction.id)}
                        className="block text-xs text-amber-800/90 hover:underline dark:text-amber-200/90"
                    >
                        {correction.phase
                            ? `${correction.phase.phase_code.toUpperCase()} · ${correction.phase.phase_label}`
                            : 'Correction'}
                        {' — '}
                        {correction.field_count} field
                        {correction.field_count > 1 ? 's' : ''} proposed
                    </Link>
                ))}
            </div>
        </div>
    );
}
