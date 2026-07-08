import { Link } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowUpRight,
    Building2,
    CheckCircle2,
    ChevronDown,
    FileDown,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { show as showEmployee } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import {
    summarizeWpsPeriod,
    summarizeWpsSelection,
} from '../lib/wps-selection-summary';
import type { WpsPreview } from '../types';
import { WpsExportButton } from '../wps/wps-export-button';
import { PayrollPeriodProgress } from './payroll-period-progress';

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
    const [skippedDetailsOpen, setSkippedDetailsOpen] = useState(false);

    const periodSummary = useMemo(() => summarizeWpsPeriod(preview), [preview]);
    const exportSummary = useMemo(() => {
        if (selectedRecordIds === null) {
            return periodSummary;
        }

        return summarizeWpsSelection(preview, selectedRecordIds);
    }, [preview, selectedRecordIds, periodSummary]);

    const {
        selectedCount: periodTotal,
        eligibleCount: periodEligible,
        skippedInSelection: periodSkipped,
        companyConfigMissing,
        skippedRecords: periodSkippedRecords,
        companyIssues,
    } = periodSummary;

    const {
        selectedCount: exportSelected,
        eligibleCount: exportEligible,
        skippedInSelection: exportSkipped,
        skippedRecords: exportSkippedRecords,
    } = exportSummary;

    const usesSelection = selectedRecordIds !== null;
    const usesPartialSelection = usesSelection && exportSelected < periodTotal;
    const progressPercent =
        periodTotal === 0
            ? 0
            : Math.round((periodEligible / periodTotal) * 100);

    const isReady =
        periodEligible > 0 && periodSkipped === 0 && !companyConfigMissing;
    const hasPartial = periodEligible > 0 && periodSkipped > 0;
    const isBlocked = periodEligible === 0;

    const statusLabel = companyConfigMissing
        ? 'Setup required'
        : exportSelected === 0
          ? 'None selected'
          : isReady
            ? 'Ready'
            : hasPartial
              ? 'Review needed'
              : 'No records';
    const statusClassName = companyConfigMissing
        ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-200'
        : exportSelected === 0
          ? 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200'
          : isReady
            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200'
            : hasPartial
              ? 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-200'
              : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200';

    const helperText = companyConfigMissing
        ? 'Configure MOL UID and agent code in company settings before exporting.'
        : exportSelected === 0
          ? 'Select employees in the payroll records table to include them in WPS export.'
          : usesPartialSelection
            ? `${exportSelected} of ${periodTotal} selected for export (${exportEligible} eligible). ${periodEligible} eligible in this pay period.`
            : isReady
              ? `All ${periodEligible} payroll record${periodEligible === 1 ? '' : 's'} in this period are eligible for WPS export.`
              : isBlocked
                ? periodSkipped > 0
                    ? 'Fix the issues below before exporting WPS files for this period.'
                    : 'No payroll records are eligible for WPS export in this period.'
                : `${periodEligible} eligible and ${periodSkipped} warning${periodSkipped === 1 ? '' : 's'} in this pay period.`;

    const skippedRecords = usesSelection
        ? exportSkippedRecords
        : periodSkippedRecords;

    return (
        <Card className="relative overflow-hidden glass-card border-border/60 dark:border-white/10">
            <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-sky-500/8 via-transparent to-transparent opacity-80" />
            <CardContent className="relative z-10 space-y-5 p-5">
                <div className="flex min-w-0 items-start gap-3">
                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-sky-500/20 bg-sky-500/10 text-sky-600 shadow-inner dark:text-sky-300">
                        <FileDown className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-base font-semibold tracking-tight">
                                WPS export
                            </h3>
                            <Badge
                                variant="outline"
                                className={cn('rounded-lg', statusClassName)}
                            >
                                {companyConfigMissing ||
                                exportSelected === 0 ||
                                !isReady ? (
                                    <AlertCircle className="mr-1 h-3 w-3" />
                                ) : (
                                    <CheckCircle2 className="mr-1 h-3 w-3" />
                                )}
                                {statusLabel}
                            </Badge>
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {helperText}
                        </p>
                    </div>
                </div>

                {periodTotal > 0 ? (
                    <div className="space-y-2">
                        <div className="flex items-end justify-between gap-3 text-sm">
                            <span className="font-medium text-muted-foreground">
                                <span className="text-foreground">
                                    {periodEligible}
                                </span>
                                {' of '}
                                <span className="text-foreground">
                                    {periodTotal}
                                </span>
                                {' eligible in period'}
                            </span>
                            <span className="font-semibold text-foreground tabular-nums">
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
                    <WpsStat
                        label="Eligible"
                        value={periodEligible}
                        tone="emerald"
                    />
                    <WpsStat
                        label="Warnings"
                        value={periodSkipped}
                        tone="amber"
                    />
                </div>

                {usesSelection &&
                exportSelected > 0 &&
                exportSelected !== periodTotal ? (
                    <div className="rounded-xl border border-sky-500/20 bg-sky-500/5 px-4 py-3 text-sm text-sky-900 dark:text-sky-100">
                        <span className="font-semibold">{exportEligible}</span>{' '}
                        of{' '}
                        <span className="font-semibold">{exportSelected}</span>{' '}
                        selected records are eligible for export.
                        {exportSkipped > 0 ? (
                            <>
                                {' '}
                                <span className="font-semibold">
                                    {exportSkipped}
                                </span>{' '}
                                selected record
                                {exportSkipped === 1 ? '' : 's'} will be
                                excluded from the file.
                            </>
                        ) : null}
                    </div>
                ) : null}

                {companyConfigMissing && companyIssues.length > 0 ? (
                    <div className="space-y-2 rounded-xl border border-rose-500/25 bg-rose-500/5 px-4 py-3">
                        <p className="text-xs font-bold tracking-[0.14em] text-rose-700 uppercase dark:text-rose-200">
                            Company setup required
                        </p>
                        <ul className="space-y-1 text-sm text-rose-700/90 dark:text-rose-100/90">
                            {companyIssues.map((issue) => (
                                <li key={issue.reason}>{issue.reason}</li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {skippedRecords.length > 0 ? (
                    <Collapsible
                        open={skippedDetailsOpen}
                        onOpenChange={setSkippedDetailsOpen}
                    >
                        <div className="rounded-xl border border-amber-500/25 bg-amber-500/5">
                            <CollapsibleTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-auto w-full justify-between rounded-xl px-4 py-3 text-left hover:bg-amber-500/10"
                                >
                                    <span className="text-xs font-bold tracking-[0.14em] text-amber-800 uppercase dark:text-amber-200">
                                        {usesSelection && exportSkipped > 0
                                            ? `Why ${exportSkipped} selected record${exportSkipped === 1 ? '' : 's'} are excluded from export`
                                            : `${periodSkipped} warning${periodSkipped === 1 ? '' : 's'} — excluded from file`}
                                    </span>
                                    <ChevronDown
                                        className={cn(
                                            'h-4 w-4 shrink-0 text-amber-800 dark:text-amber-200',
                                            skippedDetailsOpen && 'rotate-180',
                                        )}
                                    />
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent className="px-4 pb-3">
                                <ul className="space-y-2">
                                    {skippedRecords.map((row) => (
                                        <li
                                            key={row.record_id}
                                            className="rounded-lg border border-amber-500/15 bg-background/60 px-3 py-2 text-sm dark:bg-background/20"
                                        >
                                            {row.employee_id ? (
                                                <Link
                                                    href={showEmployee.url(
                                                        row.employee_id,
                                                    )}
                                                    className="inline-flex items-center gap-1 font-medium hover:underline"
                                                >
                                                    {row.employee_name}
                                                    {row.employee_no
                                                        ? ` (${row.employee_no})`
                                                        : ''}
                                                    <ArrowUpRight className="h-3.5 w-3.5 shrink-0 opacity-70" />
                                                </Link>
                                            ) : (
                                                <p className="font-medium">
                                                    {row.employee_name}
                                                    {row.employee_no
                                                        ? ` (${row.employee_no})`
                                                        : ''}
                                                </p>
                                            )}
                                            <p className="mt-0.5 text-muted-foreground">
                                                {row.reason}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                                {usesSelection &&
                                periodSkipped > skippedRecords.length ? (
                                    <p className="mt-3 text-xs text-muted-foreground">
                                        View all warnings on the WPS export
                                        page.
                                    </p>
                                ) : null}
                            </CollapsibleContent>
                        </div>
                    </Collapsible>
                ) : null}

                <div className="rounded-xl border border-border/60 bg-muted/20 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                    <div className="mb-2 flex items-center gap-2 text-xs font-bold tracking-[0.14em] text-muted-foreground/70 uppercase">
                        <Building2 className="h-3.5 w-3.5" />
                        Company WPS details
                    </div>
                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                        <div className="flex items-center justify-between gap-3 sm:flex-col sm:items-start">
                            <dt className="text-muted-foreground">MOL UID</dt>
                            <dd
                                className={cn(
                                    'font-medium tabular-nums',
                                    !preview.company.wps_mol_uid &&
                                        'text-rose-600 dark:text-rose-300',
                                )}
                            >
                                {preview.company.wps_mol_uid ??
                                    'Not configured'}
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
                                {preview.company.wps_agent_code ??
                                    'Not configured'}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between gap-3 sm:col-span-2 sm:flex-col sm:items-start">
                            <dt className="text-muted-foreground">
                                Employer IBAN
                            </dt>
                            <dd
                                className={cn(
                                    'font-mono text-xs font-medium break-all',
                                    !preview.company.wps_employer_iban &&
                                        'text-rose-600 dark:text-rose-300',
                                )}
                            >
                                {preview.company.wps_employer_iban ??
                                    'Not configured'}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div className="flex flex-wrap gap-2 pt-1">
                    {canExport ? (
                        <WpsExportButton
                            periodId={periodId}
                            recordIds={
                                usesSelection
                                    ? (selectedRecordIds ?? [])
                                    : undefined
                            }
                            size="sm"
                            className="rounded-xl"
                            disabled={
                                exportSelected === 0 ||
                                exportEligible === 0 ||
                                companyConfigMissing
                            }
                        />
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}
