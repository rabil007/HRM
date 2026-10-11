import type { ReactElement } from 'react';
import { CANDIDATE_CONVERSION_UNAVAILABLE_TAB_MESSAGE } from '@/features/organization/employees/profile/candidate-conversion-mode';

export function CandidateConversionUnavailableTabCard(): ReactElement {
    return (
        <div className="rounded-xl border border-border bg-card p-12 text-center shadow-xs">
            <p className="text-sm font-medium text-muted-foreground">
                {CANDIDATE_CONVERSION_UNAVAILABLE_TAB_MESSAGE}
            </p>
        </div>
    );
}
