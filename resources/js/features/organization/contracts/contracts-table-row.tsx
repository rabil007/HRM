import { router } from '@inertiajs/react';
import {
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { TableCell, TableRow } from '@/components/ui/table';
import {
    contractCrewSalaryTotal,
    contractOfficeSalaryTotal,
    formatContractMoney,
    formatSalaryStructure,
} from '@/features/organization/contracts/contracts-format';
import type { ContractListItem } from '@/features/organization/contracts/types';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

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
            <TableCell
                className={cn(dataTableCellPrimaryClass(), 'min-w-[200px]')}
            >
                <div className="flex min-w-0 items-center gap-3">
                    <EmployeeProfileLink
                        employeeId={contract.employee_id}
                        stopRowNavigation
                        className="shrink-0"
                    >
                        <EmployeeAvatar
                            name={contract.employee_name}
                            image={contract.employee_image}
                            size="sm"
                        />
                    </EmployeeProfileLink>
                    <div className="min-w-0">
                        <EmployeeProfileLink
                            employeeId={contract.employee_id}
                            className="block truncate text-sm font-semibold text-foreground hover:text-primary"
                            stopRowNavigation
                        >
                            {contract.employee_name}
                        </EmployeeProfileLink>
                        <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                            {contract.employee_no}
                        </p>
                        {(contract.department_name || contract.position_title) ? (
                            <p className="truncate text-[11px] text-muted-foreground/60">
                                {[contract.department_name, contract.position_title]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        ) : null}
                    </div>
                </div>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'text-right')}>
                {formatContractMoney(contract.basic_salary)}
            </TableCell>
            {showOfficeColumns ? (
                <>
                    <TableCell className={cn(dataTableCellClass(), 'text-right')}>
                        {formatContractMoney(contract.housing_allowance)}
                    </TableCell>
                    <TableCell className={cn(dataTableCellClass(), 'text-right')}>
                        {formatContractMoney(contract.transport_allowance)}
                    </TableCell>
                    <TableCell className={cn(dataTableCellClass(), 'text-right')}>
                        {formatContractMoney(contract.other_allowances)}
                    </TableCell>
                    <TableCell
                        className={cn(
                            dataTableCellClass(),
                            'text-right font-semibold text-foreground',
                        )}
                    >
                        {formatContractMoney(
                            contractOfficeSalaryTotal(contract),
                        )}
                    </TableCell>
                </>
            ) : null}
            {showCrewColumns ? (
                <>
                    <TableCell className={cn(dataTableCellClass(), 'text-right')}>
                        {formatContractMoney(
                            contract.supplementary_allowance,
                        )}
                    </TableCell>
                    <TableCell className={cn(dataTableCellClass(), 'text-right')}>
                        {formatContractMoney(contract.site_allowance)}
                    </TableCell>
                    <TableCell
                        className={cn(
                            dataTableCellClass(),
                            'text-right font-semibold text-foreground',
                        )}
                    >
                        {formatContractMoney(contractCrewSalaryTotal(contract))}
                    </TableCell>
                </>
            ) : null}
            <TableCell className={dataTableCellClass()}>
                {formatSalaryStructure(contract.salary_structure)}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span
                    className="max-w-[160px] truncate font-mono text-xs text-foreground/90 block"
                    title={contract.labor_contract_id ?? undefined}
                >
                    {contract.labor_contract_id || '—'}
                </span>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span className="tabular-nums text-sm text-muted-foreground">
                    {contract.total_contracts}
                </span>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatDisplayDate(contract.start_date)}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatDisplayDate(contract.end_date)}
            </TableCell>
        </TableRow>
    );
}
