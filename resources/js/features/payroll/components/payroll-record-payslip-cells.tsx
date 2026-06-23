import { Link } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import {
    download as downloadPayslip,
    show as showPayslip,
} from '@/actions/App/Http/Controllers/Payroll/PayslipController';
import { dataTableActionsCellClass, dataTableCellClass } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableCell } from '@/components/ui/table';
import type { PayrollRecordDeliveryFields } from '../types';

type PayrollRecordPayslipCellsProps = PayrollRecordDeliveryFields & {
    recordId: number;
    canViewPayslips: boolean;
};

export function PayrollRecordPayslipStatusCell({
    has_payslip,
    wps_status_label,
}: Pick<PayrollRecordPayslipCellsProps, 'has_payslip' | 'wps_status_label'>) {
    return (
        <TableCell className={dataTableCellClass()}>
            <div className="flex flex-col gap-1">
                <Badge variant={has_payslip ? 'default' : 'outline'}>
                    {has_payslip ? 'Generated' : 'Pending'}
                </Badge>
                {wps_status_label ? (
                    <Badge variant="secondary" className="w-fit text-xs">
                        WPS: {wps_status_label}
                    </Badge>
                ) : null}
            </div>
        </TableCell>
    );
}

export function PayrollRecordPayslipActionsCell({
    recordId,
    canViewPayslips,
}: Pick<PayrollRecordPayslipCellsProps, 'recordId' | 'canViewPayslips'>) {
    if (!canViewPayslips) {
        return <TableCell className={dataTableActionsCellClass()} />;
    }

    return (
        <TableCell className={dataTableActionsCellClass()}>
            <div className="flex items-center justify-end gap-2">
                <Button asChild variant="ghost" size="icon">
                    <Link href={showPayslip.url(recordId)} target="_blank">
                        <FileText className="h-4 w-4" />
                    </Link>
                </Button>
                <Button asChild variant="ghost" size="icon">
                    <a href={downloadPayslip.url(recordId)}>
                        <Download className="h-4 w-4" />
                    </a>
                </Button>
            </div>
        </TableCell>
    );
}
