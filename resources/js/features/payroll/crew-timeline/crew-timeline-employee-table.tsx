import { useState } from 'react';
import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { CrewTimelineLinesDialog } from './crew-timeline-lines-dialog';
import type { CrewTimelineEmployeeSummary } from './types';

type WarningDetail = {
    label: string;
    remarks: string | null;
    from: string | null;
    to: string | null;
};

function PhaseRange({
    from,
    to,
    days,
}: {
    from: string | null;
    to: string | null;
    days: number;
}) {
    if (!from && !to && days <= 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="space-y-1">
            <div className="flex items-center gap-1.5 text-xs">
                <span className="font-medium">{formatDisplayDate(from)}</span>
                <span className="text-muted-foreground">→</span>
                <span className="font-medium">{formatDisplayDate(to)}</span>
            </div>
            <Badge
                variant="outline"
                className="rounded-md border-border/60 bg-muted/40 px-1.5 py-0 text-[10px] font-semibold text-muted-foreground tabular-nums"
            >
                {days.toFixed(2)} days
            </Badge>
        </div>
    );
}

function WarningCell({
    items,
    tone,
}: {
    items: WarningDetail[];
    tone: 'blocking' | 'info';
}) {
    if (items.length === 0) {
        return <span className="text-muted-foreground tabular-nums">—</span>;
    }

    const toneClass =
        tone === 'blocking'
            ? 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300'
            : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';

    return (
        <div className="flex flex-col gap-1">
            {items.map((item, index) => {
                const range =
                    item.from || item.to
                        ? `${formatDisplayDate(item.from)} → ${formatDisplayDate(item.to)}`
                        : null;

                return (
                    <Tooltip key={`${item.label}-${index}`}>
                        <TooltipTrigger asChild>
                            <Badge
                                variant="outline"
                                className={cn(
                                    'w-fit cursor-help rounded-md px-1.5 py-0 text-[10px] font-medium',
                                    toneClass,
                                )}
                            >
                                {item.label}
                            </Badge>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-xs">
                            <p className="font-semibold">{item.label}</p>
                            {range ? (
                                <p className="mt-0.5 opacity-80">{range}</p>
                            ) : null}
                            {item.remarks ? (
                                <p className="mt-1 opacity-80">
                                    {item.remarks}
                                </p>
                            ) : null}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </div>
    );
}

function warningDetails(
    employee: CrewTimelineEmployeeSummary,
    blocking: boolean,
): WarningDetail[] {
    return employee.lines
        .filter((line) => line.warning && line.warning.is_blocking === blocking)
        .map((line) => ({
            label: line.warning!.label,
            remarks: line.remarks,
            from: line.from_date,
            to: line.to_date,
        }));
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
                                    <PhaseRange
                                        from={employee.sign_on_standby_from}
                                        to={employee.sign_on_standby_to}
                                        days={employee.sign_on_standby_days}
                                    />
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <PhaseRange
                                        from={employee.onsite_from}
                                        to={employee.onsite_to}
                                        days={employee.onsite_days}
                                    />
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <PhaseRange
                                        from={employee.sign_off_standby_from}
                                        to={employee.sign_off_standby_to}
                                        days={employee.sign_off_standby_days}
                                    />
                                </TableCell>
                                <TableCell
                                    className={`${dataTableCellClass()} tabular-nums`}
                                >
                                    <span className="font-semibold">
                                        {employee.total_payable_days.toFixed(2)}
                                    </span>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <WarningCell
                                        items={warningDetails(employee, true)}
                                        tone="blocking"
                                    />
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <WarningCell
                                        items={warningDetails(employee, false)}
                                        tone="info"
                                    />
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
