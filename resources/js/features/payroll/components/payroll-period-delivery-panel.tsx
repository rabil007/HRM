import { router } from '@inertiajs/react';
import { ChevronDown, Mail, Sparkles } from 'lucide-react';
import { useState } from 'react';
import {
    email as emailPayslips,
    generate as generatePayslips,
} from '@/actions/App/Http/Controllers/Payroll/PayslipController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type {
    CrewPayrollPermissions,
    PayrollPeriod,
    PayslipSummary,
    WpsPreview,
} from '../types';
import { WpsExportButton } from '../wps/wps-export-button';

export function PayrollPeriodDeliveryPanel({
    period,
    payslip_summary,
    wps_preview,
    permissions,
}: {
    period: PayrollPeriod;
    payslip_summary: PayslipSummary;
    wps_preview: WpsPreview | null;
    permissions: CrewPayrollPermissions;
}) {
    const [processing, setProcessing] = useState<'generate' | 'email' | null>(null);
    const [skippedOpen, setSkippedOpen] = useState(false);

    const showPayslipsCard =
        payslip_summary.total > 0 && permissions.payslips_view;
    const showWpsCard =
        wps_preview !== null && permissions.wps_view;

    if (!showPayslipsCard && !showWpsCard) {
        return null;
    }

    const handlePayslipAction = (action: 'generate' | 'email') => {
        setProcessing(action);

        const route = action === 'generate' ? generatePayslips : emailPayslips;

        router.post(
            route.url(),
            { period_id: period.id },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(null),
            },
        );
    };

    return (
        <div className="mb-6 grid gap-4 md:grid-cols-2">
            {showPayslipsCard ? (
                <Card className="glass-card border-border/60">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base font-semibold">Payslips</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            {payslip_summary.generated} of {payslip_summary.total} payslip
                            {payslip_summary.total === 1 ? '' : 's'} generated
                            {payslip_summary.pending > 0
                                ? ` · ${payslip_summary.pending} pending`
                                : ''}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {permissions.payslips_generate ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="rounded-xl"
                                    disabled={processing !== null}
                                    onClick={() => handlePayslipAction('generate')}
                                >
                                    <Sparkles className="mr-2 h-4 w-4" />
                                    {processing === 'generate' ? 'Generating…' : 'Generate all'}
                                </Button>
                            ) : null}
                            {permissions.payslips_email ? (
                                <Button
                                    size="sm"
                                    className="rounded-xl"
                                    disabled={processing !== null}
                                    onClick={() => handlePayslipAction('email')}
                                >
                                    <Mail className="mr-2 h-4 w-4" />
                                    {processing === 'email' ? 'Sending…' : 'Email all'}
                                </Button>
                            ) : null}
                        </div>
                    </CardContent>
                </Card>
            ) : null}

            {showWpsCard && wps_preview ? (
                <Card className="glass-card border-border/60">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base font-semibold">WPS export</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Alert>
                            <AlertDescription>
                                {wps_preview.eligible_count} eligible record
                                {wps_preview.eligible_count === 1 ? '' : 's'} for{' '}
                                {wps_preview.period.name}. MOL UID:{' '}
                                {wps_preview.company.wps_mol_uid ?? '—'} · Agent:{' '}
                                {wps_preview.company.wps_agent_code ?? '—'}
                            </AlertDescription>
                        </Alert>

                        {wps_preview.skipped.length > 0 ? (
                            <Collapsible open={skippedOpen} onOpenChange={setSkippedOpen}>
                                <CollapsibleTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-auto w-full justify-between rounded-xl px-3 py-2 text-sm"
                                    >
                                        <span>
                                            {wps_preview.skipped.length} skipped record
                                            {wps_preview.skipped.length === 1 ? '' : 's'}
                                        </span>
                                        <ChevronDown
                                            className={cn(
                                                'h-4 w-4 transition-transform',
                                                skippedOpen && 'rotate-180',
                                            )}
                                        />
                                    </Button>
                                </CollapsibleTrigger>
                                <CollapsibleContent className="pt-2">
                                    <div className="rounded-lg border">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Employee</TableHead>
                                                    <TableHead>Reason</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {wps_preview.skipped.map((row) => (
                                                    <TableRow
                                                        key={`${row.record_id}-${row.employee_no ?? 'company'}`}
                                                    >
                                                        <TableCell>
                                                            {row.employee_name}
                                                            {row.employee_no
                                                                ? ` (${row.employee_no})`
                                                                : ''}
                                                        </TableCell>
                                                        <TableCell>{row.reason}</TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </CollapsibleContent>
                            </Collapsible>
                        ) : null}

                        {permissions.wps_export ? (
                            <WpsExportButton
                                periodId={period.id}
                                size="sm"
                                className="rounded-xl"
                                disabled={wps_preview.eligible_count === 0}
                            />
                        ) : null}
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
