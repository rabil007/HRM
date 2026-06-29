import { Link } from '@inertiajs/react';
import { AlertCircle, ArrowUpRight, Building2, CheckCircle2, FileDown } from 'lucide-react';
import { index as wpsIndex } from '@/actions/App/Http/Controllers/Payroll/WpsExportController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { WpsPreview } from '../types';
import { PayrollPeriodProgress } from './payroll-period-progress';
import { WpsExportButton } from '../wps/wps-export-button';

function WpsStat({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone: 'emerald' | 'amber' | 'sky';
}) {
    const toneClassName = {
        emerald: 'border-emerald-500/20 bg-emerald-500/5 text-emerald-700 dark:text-emerald-200',
        amber: 'border-amber-500/20 bg-amber-500/5 text-amber-700 dark:text-amber-200',
        sky: 'border-sky-500/20 bg-sky-500/5 text-sky-700 dark:text-sky-200',
    }[tone];

    return (
        <div className={cn('rounded-xl border px-4 py-3', toneClassName)}>
            <p className="text-[11px] font-bold uppercase tracking-[0.14em] opacity-70">{label}</p>
            <p className="mt-1 text-2xl font-extrabold tabular-nums tracking-tight">{value}</p>
        </div>
    );
}

export function WpsDeliveryCard({
    periodId,
    preview,
    canExport,
}: {
    periodId: number;
    preview: WpsPreview;
    canExport: boolean;
}) {
    const skippedCount = preview.skipped.length;
    const totalRecords = preview.eligible_count + skippedCount;
    const progressPercent =
        totalRecords === 0 ? 0 : Math.round((preview.eligible_count / totalRecords) * 100);

    const companyConfigMissing =
        !preview.company.wps_mol_uid || !preview.company.wps_agent_code;

    const isReady =
        preview.eligible_count > 0 && skippedCount === 0 && !companyConfigMissing;
    const hasPartial = preview.eligible_count > 0 && skippedCount > 0;
    const isBlocked = preview.eligible_count === 0;

    const statusLabel = companyConfigMissing
        ? 'Setup required'
        : isReady
          ? 'Ready'
          : hasPartial
            ? 'Review needed'
            : 'No records';
    const statusClassName = companyConfigMissing
        ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-200'
        : isReady
          ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200'
          : hasPartial
            ? 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-200'
            : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200';

    const helperText = companyConfigMissing
        ? 'Configure MOL UID and agent code in company settings before exporting.'
        : isReady
          ? `All ${preview.eligible_count} record${preview.eligible_count === 1 ? '' : 's'} ready for WPS export.`
          : isBlocked
            ? skippedCount > 0
                ? 'No eligible records — open View all to see skipped employees.'
                : 'No payroll records are eligible for WPS export in this period.'
            : `${preview.eligible_count} eligible · ${skippedCount} skipped for ${preview.period.name}.`;

    return (
        <Card className="glass-card border-border/60 relative overflow-hidden dark:border-white/10">
            <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-sky-500/8 via-transparent to-transparent opacity-80" />
            <CardContent className="relative z-10 space-y-5 p-5">
                <div className="flex min-w-0 items-start gap-3">
                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-sky-500/20 bg-sky-500/10 text-sky-600 shadow-inner dark:text-sky-300">
                        <FileDown className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-base font-semibold tracking-tight">WPS export</h3>
                            <Badge variant="outline" className={cn('rounded-lg', statusClassName)}>
                                {companyConfigMissing ? (
                                    <AlertCircle className="mr-1 h-3 w-3" />
                                ) : isReady ? (
                                    <CheckCircle2 className="mr-1 h-3 w-3" />
                                ) : (
                                    <AlertCircle className="mr-1 h-3 w-3" />
                                )}
                                {statusLabel}
                            </Badge>
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">{helperText}</p>
                    </div>
                </div>

                {totalRecords > 0 ? (
                    <div className="space-y-2">
                        <div className="flex items-end justify-between gap-3 text-sm">
                            <span className="font-medium text-muted-foreground">
                                <span className="text-foreground">{preview.eligible_count}</span>
                                {' of '}
                                <span className="text-foreground">{totalRecords}</span>
                                {' eligible'}
                            </span>
                            <span className="font-semibold tabular-nums text-foreground">
                                {progressPercent}%
                            </span>
                        </div>
                        <PayrollPeriodProgress
                            value={progressPercent}
                            barClassName={
                                isReady
                                    ? 'from-emerald-500 to-emerald-400'
                                    : hasPartial
                                      ? 'from-sky-500 to-sky-400'
                                      : 'from-amber-500 to-amber-400'
                            }
                        />
                    </div>
                ) : null}

                <div className="grid grid-cols-2 gap-3">
                    <WpsStat label="Eligible" value={preview.eligible_count} tone="emerald" />
                    <WpsStat label="Skipped" value={skippedCount} tone="amber" />
                </div>

                <div className="rounded-xl border border-border/60 bg-muted/20 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                    <div className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.14em] text-muted-foreground/70">
                        <Building2 className="h-3.5 w-3.5" />
                        Company WPS details
                    </div>
                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                        <div className="flex items-center justify-between gap-3 sm:flex-col sm:items-start">
                            <dt className="text-muted-foreground">MOL UID</dt>
                            <dd
                                className={cn(
                                    'font-medium tabular-nums',
                                    !preview.company.wps_mol_uid && 'text-rose-600 dark:text-rose-300',
                                )}
                            >
                                {preview.company.wps_mol_uid ?? 'Not configured'}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between gap-3 sm:flex-col sm:items-start">
                            <dt className="text-muted-foreground">Agent</dt>
                            <dd
                                className={cn(
                                    'font-medium tabular-nums',
                                    !preview.company.wps_agent_code &&
                                        'text-rose-600 dark:text-rose-300',
                                )}
                            >
                                {preview.company.wps_agent_code ?? 'Not configured'}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div className="flex flex-wrap gap-2 pt-1">
                    <Button variant="outline" size="sm" className="rounded-xl" asChild>
                        <Link
                            href={wpsIndex.url({ query: { period_id: String(periodId) } })}
                            preserveScroll
                        >
                            View all
                            <ArrowUpRight className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                    {canExport ? (
                        <WpsExportButton
                            periodId={periodId}
                            size="sm"
                            className="rounded-xl"
                            disabled={preview.eligible_count === 0 || companyConfigMissing}
                        />
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}
