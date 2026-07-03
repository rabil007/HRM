import { router } from '@inertiajs/react';
import {
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import {
    formatContractMoney,
    formatContractStatus,
    formatContractType,
    formatPayrollCategory,
} from '@/features/organization/contracts/contracts-format';
import type { ContractListItem } from '@/features/organization/contracts/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

function statusBadgeClass(status: string | null | undefined): string {
    switch (status) {
        case 'active':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400';
        case 'ended':
            return 'border-red-500/30 bg-red-500/10 text-red-400';
        case 'draft':
            return 'border-violet-500/30 bg-violet-500/10 text-violet-400';
        default:
            return 'border-border dark:border-white/10';
    }
}

export function ContractsTableRow({
    contract,
    browseHref,
    showOfficeColumns,
    showCrewColumns,
}: {
    contract: ContractListItem;
    browseHref: string;
    showOfficeColumns: boolean;
    showCrewColumns: boolean;
}) {
    return (
        <TableRow
            className={cn(dataTableBodyRowClass(false), 'cursor-pointer')}
            onClick={() => router.visit(browseHref)}
        >
            <TableCell className={cn(dataTableCellClass(), 'min-w-[140px]')}>
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-foreground">
                        {contract.employee_name}
                    </p>
                    <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                        {contract.employee_no}
                    </p>
                </div>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatContractType(contract.contract_type)}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatPayrollCategory(contract.payroll_category)}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <Badge
                    variant="outline"
                    className={cn(
                        'font-normal',
                        statusBadgeClass(contract.status),
                    )}
                >
                    {formatContractStatus(contract.status)}
                </Badge>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatDisplayDate(contract.start_date)}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatDisplayDate(contract.end_date)}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatContractMoney(contract.basic_salary)}
            </TableCell>
            {showOfficeColumns ? (
                <>
                    <TableCell className={dataTableCellClass()}>
                        {formatContractMoney(contract.housing_allowance)}
                    </TableCell>
                    <TableCell className={dataTableCellClass()}>
                        {formatContractMoney(contract.transport_allowance)}
                    </TableCell>
                    <TableCell className={dataTableCellClass()}>
                        {formatContractMoney(contract.other_allowances)}
                    </TableCell>
                </>
            ) : null}
            {showCrewColumns ? (
                <>
                    <TableCell className={dataTableCellClass()}>
                        {formatContractMoney(
                            contract.supplementary_allowance,
                        )}
                    </TableCell>
                    <TableCell className={dataTableCellClass()}>
                        {formatContractMoney(contract.site_allowance)}
                    </TableCell>
                </>
            ) : null}
            <TableCell className={dataTableCellClass()}>
                {contract.profile_template_name?.trim() || 'Default'}
            </TableCell>
        </TableRow>
    );
}
