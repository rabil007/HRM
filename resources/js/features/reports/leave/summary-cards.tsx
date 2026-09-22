import { Card, CardContent } from '@/components/ui/card';
import type { LeaveReportDayBucket, LeaveReportProps } from './types';

function formatDays(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function DayCard({
    label,
    value,
    detail,
    className,
}: {
    label: string;
    value: string;
    detail?: string;
    className?: string;
}) {
    return (
        <Card className="h-full">
            <CardContent className="p-3">
                <p className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
                <p
                    className={`mt-1 text-xl font-bold tabular-nums ${className ?? ''}`}
                >
                    {value}
                </p>
                {detail ? (
                    <p className="mt-1 text-[11px] text-muted-foreground">
                        {detail}
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}

function bucketDetail(bucket: LeaveReportDayBucket): string {
    return `${formatDays(bucket.approved)} approved · ${formatDays(bucket.pending)} pending`;
}

export function LeaveReportSummaryCards({
    summary,
}: {
    summary: LeaveReportProps['summary'];
}) {
    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <DayCard
                label="Total Leave Days"
                value={formatDays(summary.total_leave_days)}
            />
            <DayCard
                label="Approved Leave Days"
                value={formatDays(summary.approved_leave_days)}
                className="text-emerald-500"
            />
            <DayCard
                label="Pending Leave Days"
                value={formatDays(summary.pending_leave_days)}
                className="text-amber-500"
            />
            <DayCard
                label="Annual Leave Days"
                value={formatDays(summary.annual.total)}
                detail={bucketDetail(summary.annual)}
                className="text-blue-500"
            />
            <DayCard
                label="Sick Leave Days"
                value={formatDays(summary.sick.total)}
                detail={bucketDetail(summary.sick)}
                className="text-cyan-500"
            />
        </div>
    );
}
