import { Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    CheckCircle2,
    Clock3,
    Download,
    FileText,
    Mail,
} from 'lucide-react';
import { useState } from 'react';
import {
    email as emailPayslips,
    index as payslipsIndex,
    downloadZip as downloadZipPayslips,
} from '@/actions/App/Http/Controllers/Payroll/PayslipController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { PayslipSummary } from '../types';
import { PayrollPeriodProgress } from './payroll-period-progress';

function PayslipStat({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone: 'emerald' | 'amber' | 'sky';
}) {
    const toneClassName = {
        emerald:
            'border-emerald-500/20 bg-emerald-500/5 text-emerald-700 dark:text-emerald-200',
        amber: 'border-amber-500/20 bg-amber-500/5 text-amber-700 dark:text-amber-200',
        sky: 'border-sky-500/20 bg-sky-500/5 text-sky-700 dark:text-sky-200',
    }[tone];

    return (
        <div className={cn('rounded-xl border px-4 py-3', toneClassName)}>
            <p className="text-[11px] font-bold tracking-[0.14em] uppercase opacity-70">
                {label}
            </p>
            <p className="mt-1 text-2xl font-extrabold tracking-tight tabular-nums">
                {value}
            </p>
        </div>
    );
}

export function PayslipDeliveryCard({
    periodId,
    summary,
    canEmail,
}: {
    periodId: number;
    summary: PayslipSummary;
    canEmail: boolean;
}) {
    const [processing, setProcessing] = useState(false);

    const progressPercent =
        summary.total === 0
            ? 0
            : Math.round((summary.generated / summary.total) * 100);
    const isComplete = summary.pending === 0 && summary.total > 0;
    const hasPartial = summary.generated > 0 && summary.pending > 0;

    const statusLabel = isComplete
        ? 'Ready'
        : hasPartial
          ? 'In progress'
          : 'Pending';
    const statusVariant = isComplete
        ? 'default'
        : hasPartial
          ? 'secondary'
          : 'outline';
    const statusClassName = isComplete
        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200'
        : hasPartial
          ? 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-200'
          : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200';

    const helperText = isComplete
        ? 'All payslips are ready to view, download, or email.'
        : summary.generated === 0
          ? 'Payslips are generated automatically when you approve the pay run.'
          : `${summary.pending} employee${summary.pending === 1 ? '' : 's'} still waiting for a payslip PDF.`;

    const handleEmailAll = () => {
        setProcessing(true);

        router.post(
            emailPayslips.url(),
            { period_id: periodId },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Card className="relative overflow-hidden glass-card border-border/60 dark:border-white/10">
            <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-violet-500/8 via-transparent to-transparent opacity-80" />
            <CardContent className="relative z-10 space-y-5 p-5">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-violet-500/20 bg-violet-500/10 text-violet-600 shadow-inner dark:text-violet-300">
                            <FileText className="h-5 w-5" />
                        </div>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h3 className="text-base font-semibold tracking-tight">
                                    Payslips
                                </h3>
                                <Badge
                                    variant={statusVariant}
                                    className={cn(
                                        'rounded-lg',
                                        statusClassName,
                                    )}
                                >
                                    {isComplete ? (
                                        <CheckCircle2 className="mr-1 h-3 w-3" />
                                    ) : (
                                        <Clock3 className="mr-1 h-3 w-3" />
                                    )}
                                    {statusLabel}
                                </Badge>
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {helperText}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="space-y-2">
                    <div className="flex items-end justify-between gap-3 text-sm">
                        <span className="font-medium text-muted-foreground">
                            <span className="text-foreground">
                                {summary.generated}
                            </span>
                            {' of '}
                            <span className="text-foreground">
                                {summary.total}
                            </span>
                            {' generated'}
                        </span>
                        <span className="font-semibold text-foreground tabular-nums">
                            {progressPercent}%
                        </span>
                    </div>
                    <PayrollPeriodProgress
                        value={progressPercent}
                        barClassName={
                            isComplete
                               ? 'from-emerald-500 to-emerald-400'
                               : hasPartial
                                 ? 'from-sky-500 to-sky-400'
                                 : undefined
                        }
                    />
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <PayslipStat
                        label="Generated"
                        value={summary.generated}
                        tone="emerald"
                    />
                    <PayslipStat
                        label="Pending"
                        value={summary.pending}
                        tone="amber"
                    />
                </div>

                <div className="flex flex-wrap gap-2 pt-1">
                    <Button
                        variant="outline"
                        size="sm"
                        className="rounded-xl"
                        asChild
                    >
                        <Link
                            href={payslipsIndex.url({
                                query: { period_id: String(periodId) },
                            })}
                            preserveScroll
                        >
                            View all
                            <ArrowUpRight className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                    {summary.generated > 0 ? (
                        <Button
                            variant="outline"
                            size="sm"
                            className="rounded-xl"
                            asChild
                        >
                            <a
                                href={downloadZipPayslips.url({
                                    query: { period_id: String(periodId) },
                                })}
                            >
                                <Download className="mr-2 h-4 w-4" />
                                Download ZIP
                            </a>
                        </Button>
                    ) : (
                        <Button
                            variant="outline"
                            size="sm"
                            className="rounded-xl"
                            disabled
                        >
                            <Download className="mr-2 h-4 w-4" />
                            Download ZIP
                        </Button>
                    )}
                    {canEmail ? (
                        <Button
                            size="sm"
                            className="rounded-xl"
                            disabled={processing || summary.generated === 0}
                            onClick={handleEmailAll}
                        >
                            <Mail className="mr-2 h-4 w-4" />
                            {processing ? 'Sending…' : 'Email all'}
                        </Button>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}
