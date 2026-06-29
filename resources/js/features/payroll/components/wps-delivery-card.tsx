import { Link } from '@inertiajs/react';
import { AlertCircle, ArrowUpRight, Building2, CheckCircle2, FileDown } from 'lucide-react';
import { useMemo } from 'react';
import { index as wpsIndex } from '@/actions/App/Http/Controllers/Payroll/WpsExportController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { summarizeWpsSelection } from '../lib/wps-selection-summary';
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
    selectedRecordIds = null,
}: {
    periodId: number;
    preview: WpsPreview;
    canExport: boolean;
    selectedRecordIds?: number[] | null;
}) {
    const selection = useMemo(() => {
        if (selectedRecordIds === null) {
            const skippedCount = preview.skipped.filter((row) => row.record_id > 0).length;

            return {
                selectedCount: preview.eligible_count + skippedCount,
                eligibleCount: preview.eligible_count,
                skippedInSelection: skippedCount,
                companyConfigMissing:
                    !preview.company.wps_mol_uid || !preview.company.wps_agent_code,
            };
        }

        return summarizeWpsSelection(preview, selectedRecordIds);
    }, [preview, selectedRecordIds]);

    const { selectedCount, eligibleCount, skippedInSelection, companyConfigMissing } = selection;
    const usesSelection = selectedRecordIds !== null;
    const totalRecords = selectedCount;
    const progressPercent =
        totalRecords === 0 ? 0 : Math.round((eligibleCount / totalRecords) * 100);

    const isReady = eligibleCount > 0 && skippedInSelection === 0 && !companyConfigMissing;
    const hasPartial = eligibleCount > 0 && skippedInSelection > 0;
    const isBlocked = eligibleCount === 0;

    const statusLabel = companyConfigMissing
        ? 'Setup required'
        : selectedCount === 0
          ? 'None selected'
          : isReady
            ? 'Ready'
            : hasPartial
              ? 'Review needed'
              : 'No records';
    const statusClassName = companyConfigMissing
        ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-200'
        : selectedCount === 0
          ? 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200'
          : isReady
            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200'
            : hasPartial
              ? 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-200'
              : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200';

    const helperText = companyConfigMissing
        ? 'Configure MOL UID and agent code in company settings before exporting.'
        : selectedCount === 0
          ? 'Select employees in the payroll records table to include them in WPS export.'
          : usesSelection
            ? `${selectedCount} selected in the table · ${eligibleCount} eligible for export.`
            : isReady
              ? `All ${eligibleCount} record${eligibleCount === 1 ? '' : 's'} ready for WPS export.`
              : isBlocked
                ? skippedInSelection > 0
                    ? 'No eligible records in selection — open View all for details.'
                    : 'No payroll records are eligible for WPS export in this period.'
                : `${eligibleCount} eligible · ${skippedInSelection} skipped in selection.`;

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
                                {companyConfigMissing || selectedCount === 0 || !isReady ? (
                                    <AlertCircle className="mr-1 h-3 w-3" />
                                ) : (
                                    <CheckCircle2 className="mr-1 h-3 w-3" />
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
                                <span className="text-foreground">{eligibleCount}</span>
                                {' of '}
                                <span className="text-foreground">{totalRecords}</span>
                                {usesSelection ? ' selected eligible' : ' eligible'}
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
                    <WpsStat label="Eligible" value={eligibleCount} tone="emerald" />
                    <WpsStat
                        label={usesSelection ? 'Skipped' : 'Skipped'}
                        value={skippedInSelection}
                        tone="amber"
                    />
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
                            recordIds={usesSelection ? selectedRecordIds ?? [] : undefined}
                            size="sm"
                            className="rounded-xl"
                            disabled={
                                selectedCount === 0 || eligibleCount === 0 || companyConfigMissing
                            }
                        />
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}
