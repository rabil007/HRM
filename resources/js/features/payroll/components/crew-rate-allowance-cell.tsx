import { cn } from '@/lib/utils';
import { formatTimesheetAmount } from '../types';

type CrewRateAllowanceCellProps = {
    dailyRate: string | null | undefined;
    calculation: string | null;
    amount: string | null | undefined;
    className?: string;
};

export function CrewRateAllowanceCell({
    dailyRate,
    calculation,
    amount,
    className,
}: CrewRateAllowanceCellProps) {
    const rateValue = Number(dailyRate ?? 0);
    const amountValue = Number(amount ?? 0);
    const hasAmount = amountValue > 0;
    const hasRate = rateValue > 0;

    if (!hasRate && !hasAmount && !calculation) {
        return (
            <span className="text-xs text-muted-foreground/40">—</span>
        );
    }

    return (
        <div className={cn('flex flex-col gap-0.5', className)}>
            {hasRate ? (
                <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                    {formatTimesheetAmount(dailyRate)}/day
                </span>
            ) : null}
            {calculation ? (
                <span className="text-[11px] text-muted-foreground/80 tabular-nums">
                    {calculation}
                </span>
            ) : null}
            {hasAmount ? (
                <span className="text-sm font-medium tabular-nums">
                    {formatTimesheetAmount(amount)}
                </span>
            ) : (
                <span className="text-xs text-muted-foreground/40">—</span>
            )}
        </div>
    );
}

export function buildCrewRateCalculation(
    standbyDays: number,
    onsiteDays: number,
    dailyRate: number,
    options: { standby?: boolean; onsite?: boolean } = {},
): string | null {
    const includeStandby = options.standby ?? true;
    const includeOnsite = options.onsite ?? true;
    const parts: string[] = [];

    if (includeStandby && standbyDays > 0) {
        parts.push(`${formatDayCount(standbyDays)}×${formatRate(dailyRate)}`);
    }

    if (includeOnsite && onsiteDays > 0) {
        parts.push(`${formatDayCount(onsiteDays)}×${formatRate(dailyRate)}`);
    }

    return parts.length > 0 ? parts.join(' + ') : null;
}

export function crewBasicPayAmount(
    standbyDays: number,
    onsiteDays: number,
    basicDaily: number,
): number {
    return roundMoney(standbyDays * basicDaily + onsiteDays * basicDaily);
}

export function crewSupplementaryPayAmount(
    standbyDays: number,
    onsiteDays: number,
    supplementaryDaily: number,
): number {
    return roundMoney(
        standbyDays * supplementaryDaily + onsiteDays * supplementaryDaily,
    );
}

export function crewSitePayAmount(
    onsiteDays: number,
    siteDaily: number,
): number {
    return roundMoney(onsiteDays * siteDaily);
}

function formatDayCount(days: number): string {
    return Number.isInteger(days) ? String(days) : days.toFixed(2);
}

function formatRate(rate: number): string {
    return Number.isInteger(rate) ? String(rate) : rate.toFixed(2);
}

function roundMoney(amount: number): number {
    return Math.round(amount * 100) / 100;
}
