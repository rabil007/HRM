import { router } from '@inertiajs/react';
import {
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import {
    contractCrewSalaryTotal,
    contractOfficeSalaryTotal,
    formatContractMoney,
} from '@/features/organization/contracts/contracts-format';
import type { ContractListItem } from '@/features/organization/contracts/types';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { RecordSelectionCell } from '@/features/organization/shared/record-selection-checkbox';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

function deriveLifecycle(
    endDate: string | null | undefined,
): 'active' | 'ending_30' | 'ending_60' | 'ending_90' | 'ended' | null {
    if (!endDate) {
        return null;
    }

    const end = new Date(endDate);
    const now = new Date();
    now.setHours(0, 0, 0, 0);

    if (end < now) {
        return 'ended';
    }

    const daysLeft = Math.ceil(
        (end.getTime() - now.getTime()) / (1000 * 60 * 60 * 24),
    );

    if (daysLeft <= 30) {
        return 'ending_30';
    }

    if (daysLeft <= 60) {
        return 'ending_60';
    }

    if (daysLeft <= 90) {
        return 'ending_90';
    }

    return 'active';
}

const LIFECYCLE_BADGE_CONFIG = {
    active: {
        label: 'Active',
        className:
            'bg-emerald-500/10 text-emerald-500 border-emerald-500/20 hover:bg-emerald-500/10',
    },
    ending_30: {
        label: 'Ending in 30d',
        className:
            'bg-sky-500/10 text-sky-500 border-sky-500/20 hover:bg-sky-500/10',
    },
    ending_60: {
        label: 'Ending in 60d',
        className:
            'bg-amber-500/10 text-amber-500 border-amber-500/20 hover:bg-amber-500/10',
    },
    ending_90: {
        label: 'Ending in 90d',
        className:
            'bg-orange-500/10 text-orange-500 border-orange-500/20 hover:bg-orange-500/10',
    },
    ended: {
        label: 'Ended',
        className:
            'bg-red-500/10 text-red-500 border-red-500/20 hover:bg-red-500/10',
    },
} as const;

const LIFECYCLE_ROW_ACCENT = {
    active: '',
    ending_30: 'border-l-2 border-l-sky-500/40',
    ending_60: 'border-l-2 border-l-amber-500/40',
    ending_90: 'border-l-2 border-l-orange-500/40',
    ended: 'border-l-2 border-l-red-500/30',
} as const;

export function ContractsTableRow({
    contract,
    showHref,
    showOfficeColumns,
    showCrewColumns,
    selected = false,
    onToggleSelection,
}: {
    contract: ContractListItem;
    showHref: string;
    showOfficeColumns: boolean;
    showCrewColumns: boolean;
    selected?: boolean;
    onToggleSelection?: () => void;
}) {
    const lifecycle = deriveLifecycle(contract.end_date);
    const rowAccent = lifecycle ? LIFECYCLE_ROW_ACCENT[lifecycle] : '';

    return (
        <TableRow
            className={cn(
                dataTableBodyRowClass(false),
                'cursor-pointer',
                rowAccent,
            )}
            onClick={() => router.visit(showHref)}
        >
            <RecordSelectionCell
                checked={selected}
                onToggle={() => onToggleSelection?.()}
                label={`Select contract for ${contract.employee_name}`}
            />
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
                        {contract.department_name || contract.position_title ? (
                            <p className="truncate text-[11px] text-muted-foreground/60">
                                {[
                                    contract.department_name,
                                    contract.position_title,
                                ]
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
                    <TableCell
                        className={cn(dataTableCellClass(), 'text-right')}
                    >
                        {formatContractMoney(contract.housing_allowance)}
                    </TableCell>
                    <TableCell
                        className={cn(dataTableCellClass(), 'text-right')}
                    >
                        {formatContractMoney(contract.transport_allowance)}
                    </TableCell>
                    <TableCell
                        className={cn(dataTableCellClass(), 'text-right')}
                    >
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
                    <TableCell
                        className={cn(dataTableCellClass(), 'text-right')}
                    >
                        {formatContractMoney(contract.supplementary_allowance)}
                    </TableCell>
                    <TableCell
                        className={cn(dataTableCellClass(), 'text-right')}
                    >
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
                <span
                    className="block max-w-[160px] truncate font-mono text-xs text-foreground/90"
                    title={contract.labor_contract_id ?? undefined}
                >
                    {contract.labor_contract_id || '—'}
                </span>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span className="text-sm text-muted-foreground tabular-nums">
                    {contract.total_contracts}
                </span>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatDisplayDate(contract.start_date)}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <div className="flex flex-col gap-1">
                    <span className="text-sm text-muted-foreground">
                        {formatDisplayDate(contract.end_date)}
                    </span>
                    {lifecycle ? (
                        <Badge
                            variant="outline"
                            className={cn(
                                'w-fit px-1.5 py-0 text-[10px] font-medium',
                                LIFECYCLE_BADGE_CONFIG[lifecycle].className,
                            )}
                        >
                            {LIFECYCLE_BADGE_CONFIG[lifecycle].label}
                        </Badge>
                    ) : null}
                </div>
            </TableCell>
        </TableRow>
    );
}
