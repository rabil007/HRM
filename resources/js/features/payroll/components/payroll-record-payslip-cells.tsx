import { Download, FileText } from 'lucide-react';
import {
    download as downloadPayslip,
    show as showPayslip,
} from '@/actions/App/Http/Controllers/Payroll/PayslipController';
import { dataTableCellClass } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableCell } from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { PayrollRecordDeliveryFields } from '../types';

type PayrollRecordPayslipCellsProps = PayrollRecordDeliveryFields & {
    recordId: number;
};

export function PayrollRecordPayslipStatusCell({
    has_payslip,
    wps_status_label,
    isLiveUpdating = false,
}: Pick<PayrollRecordPayslipCellsProps, 'has_payslip' | 'wps_status_label'> & {
    isLiveUpdating?: boolean;
}) {
    return (
        <TableCell className={dataTableCellClass()}>
            <div className="flex flex-col gap-1">
                <Badge
                    variant={has_payslip ? 'default' : 'secondary'}
                    className={cn(
                        !has_payslip && isLiveUpdating && 'animate-pulse',
                    )}
                >
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
    has_payslip = false,
}: {
    recordId: number;
    has_payslip?: boolean;
}) {
    return (
        <>
            <Tooltip>
                <TooltipTrigger asChild>
                    {has_payslip ? (
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
                    ) : (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 rounded-lg"
                            disabled
                            aria-label="View payslip (Not generated)"
                        >
                            <FileText className="h-4 w-4" />
                        </Button>
                    )}
                </TooltipTrigger>
                <TooltipContent>
                    {has_payslip ? 'View payslip' : 'Payslip not generated'}
                </TooltipContent>
            </Tooltip>
            <Tooltip>
                <TooltipTrigger asChild>
                    {has_payslip ? (
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
                    ) : (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 rounded-lg"
                            disabled
                            aria-label="Download payslip (Not generated)"
                        >
                            <Download className="h-4 w-4" />
                        </Button>
                    )}
                </TooltipTrigger>
                <TooltipContent>
                    {has_payslip ? 'Download payslip' : 'Payslip not generated'}
                </TooltipContent>
            </Tooltip>
        </>
    );
}
