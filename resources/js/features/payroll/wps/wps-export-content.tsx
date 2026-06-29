import { Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    Building2,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    FileDown,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { show as payrollShow } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import { index as wpsIndex } from '@/actions/App/Http/Controllers/Payroll/WpsExportController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { PayrollCategoryBadge } from '../components/payroll-category-badge';
import { PayrollPeriodStatusBadge } from '../components/payroll-period-status-badge';
import type { WpsExportPageProps, WpsPeriodOption } from './types';
import { WpsExportButton } from './wps-export-button';

function formatPeriodDateRange(period: WpsPeriodOption): string {
    return `${formatDisplayDate(period.start_date)} — ${formatDisplayDate(period.end_date)}`;
}

function periodSearchKeywords(period: WpsPeriodOption): string {
    return [
        period.name,
        period.status_label,
        period.payroll_category_label,
        period.start_date ?? '',
        period.end_date ?? '',
    ].join(' ');
}

export function WpsExportContent({
    periods,
    selected_period_id,
    preview,
    permissions,
}: WpsExportPageProps) {
    const [periodId, setPeriodId] = useState(String(selected_period_id ?? ''));
    const [skippedOpen, setSkippedOpen] = useState(true);

    const selectedPeriod = useMemo(
        () => periods.find((period) => period.id === selected_period_id) ?? null,
        [periods, selected_period_id],
    );

    const loadPreview = (nextPeriodId: string) => {
        setPeriodId(nextPeriodId);
        router.get(
            wpsIndex.url(),
            { period_id: nextPeriodId || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const companyConfigMissing =
        preview !== null &&
        (!preview.company.wps_mol_uid ||
            !preview.company.wps_agent_code ||
            !preview.company.wps_employer_iban);

    const allEligible =
        preview !== null &&
        preview.eligible_count > 0 &&
        preview.skipped.length === 0 &&
        !companyConfigMissing;

    const noEligible =
        preview !== null && preview.eligible_count === 0;

    return (
        <Main>
            <PageHeader
                kicker="Payroll"
                title="WPS export"
                description="Generate UAE WPS SIF or Excel files for approved or paid payroll records."
                right={
                    preview && permissions.export && selected_period_id ? (
                        <WpsExportButton
                            periodId={selected_period_id}
                            disabled={preview.eligible_count === 0}
                            className="h-12 rounded-xl px-6"
                        />
                    ) : null
                }
            />

            <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div className="min-w-0 flex-1 space-y-2">
                    <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Pay period
                    </label>
                    <AppSelect
                        value={periodId}
                        onValueChange={loadPreview}
                        placeholder="Choose a pay period"
                        searchPlaceholder="Search periods..."
                        variant="card"
                        className="w-full"
                    >
                        {periods.map((period) => (
                            <AppSelectItem
                                key={period.id}
                                value={String(period.id)}
                                keywords={periodSearchKeywords(period)}
                            >
                                <span className="flex flex-col gap-0.5 text-left">
                                    <span className="font-medium">{period.name}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {period.status_label} · {period.payroll_category_label} ·{' '}
                                        {formatPeriodDateRange(period)}
                                    </span>
                                </span>
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>
            </div>

            {!preview ? (
                <EmptyState
                    icon={<FileDown className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />}
                    title="No period selected"
                    description="Choose a pay period above to preview eligible employees and export WPS files."
                />
            ) : (
                <div className="space-y-6">
                    {selectedPeriod ? (
                        <Card className="glass-card border-border/60">
                            <CardContent className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                                <div className="space-y-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-lg font-semibold">{selectedPeriod.name}</h2>
                                        <PayrollPeriodStatusBadge
                                            status={selectedPeriod.status}
                                            label={selectedPeriod.status_label}
                                        />
                                        <PayrollCategoryBadge
                                            category={selectedPeriod.payroll_category}
                                        />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {formatPeriodDateRange(selectedPeriod)} · Payment period for WPS
                                        export
                                    </p>
                                </div>
                                <Button variant="outline" className="rounded-xl" asChild>
                                    <Link
                                        href={payrollShow.url(selectedPeriod.id, {
                                            query: { tab: 'payroll' },
                                        })}
                                    >
                                        Open pay run
                                        <ChevronRight className="ml-2 h-4 w-4" />
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ) : null}

                    {companyConfigMissing ? (
                        <Alert variant="destructive">
                            <AlertCircle className="h-4 w-4" />
                            <AlertTitle>Company WPS configuration incomplete</AlertTitle>
                            <AlertDescription>
                                Set the WPS MOL UID and agent code on the company profile before
                                exporting. Employees cannot be included until company WPS fields are
                                configured.
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    {allEligible ? (
                        <Alert className="border-emerald-500/30 bg-emerald-500/10">
                            <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                            <AlertTitle>Ready to export</AlertTitle>
                            <AlertDescription>
                                All {preview.eligible_count} payroll record
                                {preview.eligible_count === 1 ? '' : 's'} in this period are eligible
                                for WPS export.
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    {noEligible ? (
                        <Alert>
                            <AlertCircle className="h-4 w-4" />
                            <AlertTitle>No eligible records</AlertTitle>
                            <AlertDescription>
                                No payroll records in this period meet WPS requirements. Review skipped
                                employees below and fix missing contract IDs, bank accounts, or approval
                                status.
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    <div className="grid gap-4 md:grid-cols-3">
                        <Card className="glass-card border-emerald-500/15">
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Eligible records
                                </CardTitle>
                                <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                            </CardHeader>
                            <CardContent>
                                <p className="text-3xl font-bold tabular-nums">
                                    {preview.eligible_count}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Included in the SIF file
                                </p>
                            </CardContent>
                        </Card>

                        <Card className="glass-card border-amber-500/15">
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Skipped records
                                </CardTitle>
                                <AlertCircle
                                    className={cn(
                                        'h-4 w-4',
                                        preview.skipped.length > 0
                                            ? 'text-amber-500'
                                            : 'text-muted-foreground',
                                    )}
                                />
                            </CardHeader>
                            <CardContent>
                                <p className="text-3xl font-bold tabular-nums">
                                    {preview.skipped.length}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Missing required WPS data
                                </p>
                            </CardContent>
                        </Card>

                        <Card className="glass-card border-border/60">
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Company WPS config
                                </CardTitle>
                                <Building2 className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div>
                                    <p className="mb-1 text-[10px] font-bold uppercase tracking-wider text-muted-foreground/70">
                                        MOL UID
                                    </p>
                                    <Badge
                                        variant={
                                            preview.company.wps_mol_uid ? 'outline' : 'destructive'
                                        }
                                        className="font-mono"
                                    >
                                        {preview.company.wps_mol_uid ?? 'Not configured'}
                                    </Badge>
                                </div>
                                <div>
                                    <p className="mb-1 text-[10px] font-bold uppercase tracking-wider text-muted-foreground/70">
                                        Agent code
                                    </p>
                                    <Badge
                                        variant={
                                            preview.company.wps_agent_code
                                                ? 'outline'
                                                : 'destructive'
                                        }
                                        className="font-mono"
                                    >
                                        {preview.company.wps_agent_code ?? 'Not configured'}
                                    </Badge>
                                </div>
                                <div>
                                    <p className="mb-1 text-[10px] font-bold uppercase tracking-wider text-muted-foreground/70">
                                        Employer IBAN
                                    </p>
                                    <Badge
                                        variant={
                                            preview.company.wps_employer_iban
                                                ? 'outline'
                                                : 'destructive'
                                        }
                                        className="max-w-full font-mono text-xs break-all"
                                    >
                                        {preview.company.wps_employer_iban ?? 'Not configured'}
                                    </Badge>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {preview.skipped.length > 0 ? (
                        <Collapsible open={skippedOpen} onOpenChange={setSkippedOpen}>
                            <Card className="glass-card border-amber-500/20">
                                <CardHeader className="pb-3">
                                    <CollapsibleTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            className="h-auto w-full justify-between rounded-xl px-0 py-0 hover:bg-transparent"
                                        >
                                            <div className="text-left">
                                                <CardTitle className="text-base">
                                                    Skipped employees ({preview.skipped.length})
                                                </CardTitle>
                                                <p className="mt-1 text-sm font-normal text-muted-foreground">
                                                    These records will not be included in the SIF
                                                    file
                                                </p>
                                            </div>
                                            <ChevronDown
                                                className={cn(
                                                    'h-5 w-5 shrink-0 text-muted-foreground transition-transform',
                                                    skippedOpen && 'rotate-180',
                                                )}
                                            />
                                        </Button>
                                    </CollapsibleTrigger>
                                </CardHeader>
                                <CollapsibleContent>
                                    <CardContent className="pt-0">
                                        <OrganizationDataTable>
                                            <TableHeader>
                                                <DataTableHeaderRow>
                                                    <DataTableHead className="pl-5">
                                                        Employee
                                                    </DataTableHead>
                                                    <DataTableHead>Reason</DataTableHead>
                                                </DataTableHeaderRow>
                                            </TableHeader>
                                            <TableBody>
                                                {preview.skipped.map((row) => (
                                                    <TableRow
                                                        key={`${row.record_id}-${row.employee_no ?? 'company'}`}
                                                        className={dataTableBodyRowClass(false)}
                                                    >
                                                        <TableCell
                                                            className={dataTableCellPrimaryClass()}
                                                        >
                                                            <div className="font-semibold">
                                                                {row.employee_name}
                                                            </div>
                                                            {row.employee_no ? (
                                                                <div className="text-xs text-muted-foreground">
                                                                    {row.employee_no}
                                                                </div>
                                                            ) : null}
                                                        </TableCell>
                                                        <TableCell
                                                            className={dataTableCellClass()}
                                                        >
                                                            {row.reason}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </OrganizationDataTable>
                                    </CardContent>
                                </CollapsibleContent>
                            </Card>
                        </Collapsible>
                    ) : null}

                    {permissions.export && selected_period_id ? (
                        <div className="flex justify-end">
                            <WpsExportButton
                                periodId={selected_period_id}
                                disabled={preview.eligible_count === 0}
                                className="rounded-xl"
                            />
                        </div>
                    ) : null}
                </div>
            )}
        </Main>
    );
}
