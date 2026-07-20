import { useState } from 'react';
import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDisplayDate } from '@/lib/format-date';
import { CrewTimelineLinesDialog } from './crew-timeline-lines-dialog';
import type { CrewTimelineEmployeeSummary } from './types';

function formatRange(
    from: string | null,
    to: string | null,
    days: number,
): string {
    if (!from && !to && days <= 0) {
        return '—';
    }

    return `${formatDisplayDate(from)} → ${formatDisplayDate(to)} (${days.toFixed(2)})`;
}

export function CrewTimelineEmployeeTable({
    employees,
}: {
    employees: CrewTimelineEmployeeSummary[];
}) {
    const [selected, setSelected] =
        useState<CrewTimelineEmployeeSummary | null>(null);

    return (
        <>
            <OrganizationDataTable minWidth="min-w-[1280px]" compact>
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead>Employee</DataTableHead>
                        <DataTableHead>Rank</DataTableHead>
                        <DataTableHead>Assignment</DataTableHead>
                        <DataTableHead>Vessel</DataTableHead>
                        <DataTableHead>Sign-On Standby</DataTableHead>
                        <DataTableHead>Onsite</DataTableHead>
                        <DataTableHead>Sign-Off Standby</DataTableHead>
                        <DataTableHead>Payable days</DataTableHead>
                        <DataTableHead>Blocking</DataTableHead>
                        <DataTableHead>Info</DataTableHead>
                        <DataTableHead className="text-right">
                            Actions
                        </DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {employees.length === 0 ? (
                        <TableRow className={dataTableBodyRowClass(false)}>
                            <TableCell
                                colSpan={11}
                                className={dataTableCellClass()}
                            >
                                No preparation lines were generated.
                            </TableCell>
                        </TableRow>
                    ) : (
                        employees.map((employee) => (
                            <TableRow
                                key={employee.employee_id}
                                className={dataTableBodyRowClass(false)}
                            >
                                <TableCell className={dataTableCellClass()}>
                                    <div className="font-medium">
                                        {employee.employee_name ?? '—'}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {employee.employee_number ?? '—'}
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {employee.rank ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {employee.assignment_number ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {employee.vessel ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {formatRange(
                                        employee.sign_on_standby_from,
                                        employee.sign_on_standby_to,
                                        employee.sign_on_standby_days,
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {formatRange(
                                        employee.onsite_from,
                                        employee.onsite_to,
                                        employee.onsite_days,
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {formatRange(
                                        employee.sign_off_standby_from,
                                        employee.sign_off_standby_to,
                                        employee.sign_off_standby_days,
                                    )}
                                </TableCell>
                                <TableCell
                                    className={`${dataTableCellClass()} tabular-nums`}
                                >
                                    {employee.total_payable_days.toFixed(2)}
                                </TableCell>
                                <TableCell
                                    className={`${dataTableCellClass()} tabular-nums`}
                                >
                                    {employee.blocking_warning_count}
                                </TableCell>
                                <TableCell
                                    className={`${dataTableCellClass()} tabular-nums`}
                                >
                                    {employee.informational_warning_count}
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setSelected(employee)}
                                    >
                                        View Details
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))
                    )}
                </TableBody>
            </OrganizationDataTable>
            <CrewTimelineLinesDialog
                employee={selected}
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelected(null);
                    }
                }}
            />
        </>
    );
}
