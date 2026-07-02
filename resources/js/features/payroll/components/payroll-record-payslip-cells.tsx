import { Download, FileText } from 'lucide-react';
import {
    download as downloadPayslip,
    show as showPayslip,
} from '@/actions/App/Http/Controllers/Payroll/PayslipController';
import {
    dataTableActionsCellClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableCell } from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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

export function PayrollRecordPayslipActionButtons({
    recordId,
    canView = true,
    canDownload = true,
}: {
    recordId: number;
    canView?: boolean;
    canDownload?: boolean;
}) {
    if (!canView && !canDownload) {
        return null;
    }

    return (
        <>
            {canView ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            asChild
                            variant="ghost"
                            size="icon"
                            className="size-8 rounded-lg"
                        >
                            <a
                                href={showPayslip.url(recordId)}
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="View payslip"
                            >
                                <FileText className="h-4 w-4" />
                            </a>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>View payslip</TooltipContent>
                </Tooltip>
            ) : null}
            {canDownload ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            asChild
                            variant="ghost"
                            size="icon"
                            className="size-8 rounded-lg"
                        >
                            <a
                                href={downloadPayslip.url(recordId)}
                                aria-label="Download payslip"
                            >
                                <Download className="h-4 w-4" />
                            </a>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Download payslip</TooltipContent>
                </Tooltip>
            ) : null}
        </>
    );
}

export function PayrollRecordPayslipActionsCell({
    recordId,
    canViewPayslips,
    canShowPayslipActions = false,
}: Pick<PayrollRecordPayslipCellsProps, 'recordId' | 'canViewPayslips'> & {
    canShowPayslipActions?: boolean;
}) {
    if (!canViewPayslips) {
        return <TableCell className={dataTableActionsCellClass()} />;
    }

    return (
        <TableCell className={dataTableActionsCellClass()}>
            <div className="flex items-center justify-end gap-2">
                <PayrollRecordPayslipActionButtons
                    recordId={recordId}
                    canView={canShowPayslipActions}
                    canDownload={canShowPayslipActions}
                />
            </div>
        </TableCell>
    );
}
