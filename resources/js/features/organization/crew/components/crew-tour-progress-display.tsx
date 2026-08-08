import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { TOUR_STATUS_SEVERITY_BAR } from '@/features/organization/crew/lib/tour-of-duty';
import type { CrewTourProgressFields } from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function CrewTourProgressDisplay({
    progress,
    plannedSignoffAt,
    compact = false,
}: {
    progress: CrewTourProgressFields;
    plannedSignoffAt?: string | null;
    compact?: boolean;
}): ReactElement | null {
    const tourDays = progress.tour_of_duty_days;
    const daysOnboard = progress.days_onboard;
    const displayPercent = progress.tour_progress_display_percent;
    const severity = progress.tour_status_severity ?? 'normal';
    const barClass =
        TOUR_STATUS_SEVERITY_BAR[severity] ?? TOUR_STATUS_SEVERITY_BAR.normal;
    const signoffDate = plannedSignoffAt ?? null;

    if (tourDays == null && daysOnboard == null && !signoffDate) {
        return null;
    }

    const progressLabel =
        tourDays != null && daysOnboard != null
            ? `Onboard: ${daysOnboard} / ${tourDays} days`
            : daysOnboard != null
              ? `Onboard: ${daysOnboard} days`
              : null;

    const remaining =
        progress.remaining_tour_days != null
            ? progress.remaining_tour_days < 0
                ? `${Math.abs(progress.remaining_tour_days)} days overdue`
                : `${progress.remaining_tour_days} days remaining`
            : null;

    const percentValue =
        displayPercent != null ? Math.round(displayPercent) : null;

    return (
        <div className={cn('space-y-1.5', compact ? 'text-[11px]' : 'text-xs')}>
            {progressLabel ? (
                <p className="font-medium text-foreground">{progressLabel}</p>
            ) : null}

            {displayPercent != null ? (
                <div className="space-y-1">
                    <div
                        className="h-1.5 w-full overflow-hidden rounded-full bg-border/50"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={percentValue ?? 0}
                        aria-label={
                            progressLabel
                                ? `${progressLabel}, ${percentValue ?? 0}% of tour complete`
                                : `${percentValue ?? 0}% of tour complete`
                        }
                    >
                        <div
                            className={cn(
                                'h-full rounded-full transition-all duration-500',
                                barClass,
                            )}
                            style={{
                                width: `${displayPercent}%`,
                            }}
                        />
                    </div>
                    {percentValue != null ? (
                        <p className="sr-only">
                            Tour progress: {percentValue}% complete
                            {remaining ? `, ${remaining}` : ''}
                        </p>
                    ) : null}
                </div>
            ) : null}

            <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-muted-foreground">
                {remaining ? <span>{remaining}</span> : null}
                {signoffDate ? (
                    <span>Sign-off {formatDisplayDate(signoffDate)}</span>
                ) : null}
            </div>

            {progress.tour_status_label ? (
                <Badge
                    variant={
                        severity === 'critical'
                            ? 'destructive'
                            : severity === 'warning'
                              ? 'warning'
                              : 'outline'
                    }
                    className="text-[10px]"
                >
                    {progress.tour_status_label}
                </Badge>
            ) : null}
        </div>
    );
}
