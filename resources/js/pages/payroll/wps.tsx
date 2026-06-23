import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { exportMethod as exportWps, index as wpsIndex } from '@/actions/App/Http/Controllers/Payroll/WpsExportController';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type WpsPeriodOption = {
    id: number;
    name: string;
    start_date: string | null;
    end_date: string | null;
    status: string;
};

type WpsPreview = {
    period: { id: number; name: string };
    eligible_count: number;
    skipped: Array<{
        record_id: number;
        employee_name: string;
        employee_no: string | null;
        reason: string;
    }>;
    company: {
        wps_mol_uid: string | null;
        wps_agent_code: string | null;
    };
};

export default function WpsExportPage({
    periods,
    selected_period_id,
    preview,
    permissions,
}: {
    periods: WpsPeriodOption[];
    selected_period_id: number | null;
    preview: WpsPreview | null;
    permissions: { export: boolean };
}) {
    const [periodId, setPeriodId] = useState(String(selected_period_id ?? ''));

    const loadPreview = (nextPeriodId: string) => {
        setPeriodId(nextPeriodId);
        router.get(
            wpsIndex.url(),
            { period_id: nextPeriodId || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const handleExport = () => {
        if (!selected_period_id) {
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = exportWps.url();

        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (csrf) {
            const tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = '_token';
            tokenInput.value = csrf;
            form.appendChild(tokenInput);
        }

        const periodInput = document.createElement('input');
        periodInput.type = 'hidden';
        periodInput.name = 'period_id';
        periodInput.value = String(selected_period_id);
        form.appendChild(periodInput);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    };

    return (
        <>
            <Head title="WPS export" />
            <Main>
                <PageHeader
                    title="WPS export"
                    description="Generate UAE WPS SIF files for approved or paid payroll records."
                />

                <div className="mb-6 max-w-md">
                    <AppSelect value={periodId} onValueChange={loadPreview} placeholder="Select pay period">
                        {periods.map((period) => (
                            <AppSelectItem key={period.id} value={String(period.id)}>
                                {period.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                {preview ? (
                    <div className="space-y-4">
                        <Alert>
                            <AlertDescription>
                                {preview.eligible_count} eligible record(s) for {preview.period.name}.
                                Company WPS MOL UID: {preview.company.wps_mol_uid ?? '—'} · Agent code:{' '}
                                {preview.company.wps_agent_code ?? '—'}
                            </AlertDescription>
                        </Alert>

                        {preview.skipped.length > 0 ? (
                            <div className="rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Employee</TableHead>
                                            <TableHead>Reason</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {preview.skipped.map((row) => (
                                            <TableRow key={`${row.record_id}-${row.employee_no ?? 'company'}`}>
                                                <TableCell>
                                                    {row.employee_name}
                                                    {row.employee_no ? ` (${row.employee_no})` : ''}
                                                </TableCell>
                                                <TableCell>{row.reason}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        ) : null}

                        {permissions.export ? (
                            <Button
                                onClick={handleExport}
                                disabled={preview.eligible_count === 0}
                            >
                                <Download className="mr-2 h-4 w-4" />
                                Export SIF file
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </Main>
        </>
    );
}
