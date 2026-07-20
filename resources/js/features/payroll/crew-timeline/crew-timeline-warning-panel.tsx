import { AlertTriangle, Info } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import type { CrewTimelineSummary } from './types';

export function CrewTimelineWarningPanel({
    summary,
    isStale,
}: {
    summary: CrewTimelineSummary;
    isStale: boolean;
}) {
    if (
        !isStale &&
        summary.blocking_warning_count === 0 &&
        summary.informational_warning_count === 0
    ) {
        return null;
    }

    return (
        <div className="space-y-3">
            {isStale ? (
                <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertTitle>Timeline changed</AlertTitle>
                    <AlertDescription>
                        The Crew Operations timeline changed after this
                        preparation was created. Prepare a new version before
                        continuing.
                    </AlertDescription>
                </Alert>
            ) : null}
            {summary.blocking_warning_count > 0 ? (
                <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertTitle>
                        {summary.blocking_warning_count} blocking warning
                        {summary.blocking_warning_count === 1 ? '' : 's'}
                    </AlertTitle>
                    <AlertDescription>
                        Blocking warnings prevent submission and approval.
                        Correct Crew Operations data, then prepare a new
                        version.
                    </AlertDescription>
                </Alert>
            ) : null}
            {summary.informational_warning_count > 0 ? (
                <Alert>
                    <Info className="h-4 w-4" />
                    <AlertTitle>
                        {summary.informational_warning_count} informational
                        warning
                        {summary.informational_warning_count === 1 ? '' : 's'}
                    </AlertTitle>
                    <AlertDescription>
                        Informational warnings do not block submission or
                        approval.
                    </AlertDescription>
                </Alert>
            ) : null}
        </div>
    );
}
