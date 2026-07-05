import { cn } from '@/lib/utils';
import { formatTimesheetAmount } from '../types';

function formatNum(n: number): string {
    return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

function roundMoney(n: number): number {
    return Math.round(n * 100) / 100;
}

export function crewBasicPayAmount(
    standbyDays: number,
    onsiteDays: number,
    basicDaily: number,
): number {
    return roundMoney((standbyDays + onsiteDays) * basicDaily);
}

export function crewSitePayAmount(
    onsiteDays: number,
    siteDaily: number,
): number {
    return roundMoney(onsiteDays * siteDaily);
}

export function crewSupplementaryPayAmount(
    standbyDays: number,
    onsiteDays: number,
    supplementaryDaily: number,
): number {
    return roundMoney((standbyDays + onsiteDays) * supplementaryDaily);
}

// Keep these exported (used in table)
export { roundMoney, formatNum };

type CrewPayCell = {
    days: number;
    dailyBasic: number;
    dailySupplementary: number;
    dailySite?: number;
    /** standby_pay or (onsite_pay + site_allowance + supplementary_allowance) */
    totalAmount: number;
    variant: 'standby' | 'onsite';
    className?: string;
};

export function CrewPayColumnCell({
    days,
    dailyBasic,
    dailySupplementary,
    dailySite,
    totalAmount,
    variant,
    className,
}: CrewPayCell) {
    const hasDays = days > 0;
    const hasAmount = totalAmount > 0;
    const isStandby = variant === 'standby';

    const rateFormula = buildRateFormula(
        dailyBasic,
        dailySupplementary,
        dailySite,
    );
    const calcLine =
        hasDays && rateFormula
            ? `${formatNum(days)} × (${rateFormula})`
            : null;

    return (
        <div className={cn('flex flex-col gap-0.5', className)}>
            {hasDays ? (
                <span
                    className={cn(
                        'inline-flex w-fit items-center rounded-md border px-2 py-0.5 text-[11px] font-bold tabular-nums',
                        isStandby
                            ? 'border-blue-500/20 bg-blue-500/10 text-blue-700 dark:text-blue-300'
                            : 'border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
                    )}
                >
                    {formatNum(days)} days
                </span>
            ) : (
                <span className="inline-flex w-fit rounded-md border border-border/40 px-2 py-0.5 text-[11px] text-muted-foreground/40">
                    — days
                </span>
            )}
            {calcLine ? (
                <span className="font-mono text-[10px] text-muted-foreground/60">
                    {calcLine}
                </span>
            ) : null}
            {hasAmount ? (
                <span className="text-sm font-semibold tabular-nums">
                    {formatTimesheetAmount(String(totalAmount))}
                </span>
            ) : (
                <span className="text-xs text-muted-foreground/40">—</span>
            )}
        </div>
    );
}

export function CrewOvertimeColumnCell({
    hours,
    overtimeHourlyRate,
    totalAmount,
    className,
}: {
    hours: number;
    overtimeHourlyRate: number;
    totalAmount: number;
    className?: string;
}) {
    const hasHours = hours > 0;
    const hasAmount = totalAmount > 0;
    const calcLine =
        hasHours && overtimeHourlyRate > 0
            ? `${formatNum(hours)} × ${formatNum(overtimeHourlyRate)}`
            : null;

    return (
        <div className={cn('flex flex-col gap-0.5', className)}>
            {hasHours ? (
                <span className="inline-flex w-fit items-center rounded-md border border-orange-500/20 bg-orange-500/10 px-2 py-0.5 text-[11px] font-bold text-orange-700 tabular-nums dark:text-orange-300">
                    {formatNum(hours)} hrs
                </span>
            ) : (
                <span className="inline-flex w-fit rounded-md border border-border/40 px-2 py-0.5 text-[11px] text-muted-foreground/40">
                    — hrs
                </span>
            )}
            {calcLine ? (
                <span className="font-mono text-[10px] text-muted-foreground/60">
                    {calcLine}
                </span>
            ) : null}
            {hasAmount ? (
                <span className="text-sm font-semibold tabular-nums">
                    {formatTimesheetAmount(String(totalAmount))}
                </span>
            ) : (
                <span className="text-xs text-muted-foreground/40">—</span>
            )}
        </div>
    );
}

function buildRateFormula(
    basic: number,
    supplementary: number,
    site?: number,
): string | null {
    const parts: string[] = [];

    if (basic > 0) {
parts.push(formatNum(basic));
}

    if (supplementary > 0) {
parts.push(formatNum(supplementary));
}

    if (site !== undefined && site > 0) {
parts.push(formatNum(site));
}

    return parts.length > 0 ? parts.join(' + ') : null;
}

// Legacy — kept for any other callers
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
parts.push(`${formatNum(standbyDays)}×${formatNum(dailyRate)}`);
}

    if (includeOnsite && onsiteDays > 0) {
parts.push(`${formatNum(onsiteDays)}×${formatNum(dailyRate)}`);
}

    return parts.length > 0 ? parts.join(' + ') : null;
}

// Legacy — kept for any other callers
export function CrewRateAllowanceCell({
    dailyRate,
    calculation,
    amount,
    className,
}: {
    dailyRate?: string | null;
    calculation?: string | null;
    amount?: string | null;
    className?: string;
}) {
    const rateValue = Number(dailyRate ?? 0);
    const amountValue = Number(amount ?? 0);
    const hasAmount = amountValue > 0;
    const hasRate = rateValue > 0;

    if (!hasRate && !hasAmount && !calculation) {
        return <span className="text-xs text-muted-foreground/40">—</span>;
    }

    return (
        <div className={cn('flex flex-col gap-0.5', className)}>
            {hasRate ? (
                <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                    {formatTimesheetAmount(dailyRate ?? null)}/day
                </span>
            ) : null}
            {calculation ? (
                <span className="text-[11px] text-muted-foreground/80 tabular-nums">
                    {calculation}
                </span>
            ) : null}
            {hasAmount ? (
                <span className="text-sm font-medium tabular-nums">
                    {formatTimesheetAmount(amount ?? null)}
                </span>
            ) : (
                <span className="text-xs text-muted-foreground/40">—</span>
            )}
        </div>
    );
}
