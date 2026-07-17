import { Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    MoreHorizontal,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import { show as showEmployee } from '@/routes/organization/employees';
import type {
    CrewMovementHistoryFilters,
    CrewMovementHistoryRow,
    PhasePeriod,
    PhaseSummary,
} from './types';

function SortHead({
    column,
    label,
    filters,
    onSort,
    className,
}: {
    column?: string;
    label: string;
    filters: CrewMovementHistoryFilters;
    onSort: (column: string) => void;
    className?: string;
}) {
    if (!column) {
        return <DataTableHead className={className}>{label}</DataTableHead>;
    }

    const active = filters.sort === column;
    const Icon = active
        ? filters.direction === 'asc'
            ? ArrowUp
            : ArrowDown
        : ChevronsUpDown;

    return (
        <DataTableHead className={className}>
            <button
                type="button"
                onClick={() => onSort(column)}
                className="inline-flex items-center gap-1"
            >
                {label}
                <Icon className="size-3.5" aria-hidden />
            </button>
        </DataTableHead>
    );
}

function Periods({ summary }: { summary: PhaseSummary }) {
    if (summary.periods.length === 0) {
        return <span className="text-muted-foreground">Not recorded</span>;
    }

    const text = summary.periods
        .map(
            (period) =>
                `${formatDisplayDate(period.start)} → ${
                    period.status === 'active'
                        ? 'Ongoing'
                        : formatDisplayDate(period.end)
                }`,
        )
        .join('\n');

    return (
        <div className="max-w-[220px] space-y-1 whitespace-normal" title={text}>
            {summary.periods.slice(0, 2).map((period) => (
                <Period key={period.sequence} period={period} />
            ))}
            {summary.periods.length > 2 ? (
                <span className="text-xs text-muted-foreground">
                    +{summary.periods.length - 2} more
                </span>
            ) : null}
        </div>
    );
}

function Period({ period }: { period: PhasePeriod }) {
    return (
        <div className="text-xs">
            {formatDisplayDate(period.start)} →{' '}
            {period.status === 'active'
                ? 'Ongoing'
                : formatDisplayDate(period.end)}
        </div>
    );
}

function DateCell({
    value,
    ongoing = false,
}: {
    value: string | null;
    ongoing?: boolean;
}) {
    return ongoing ? 'Ongoing' : formatDisplayDate(value);
}

function Cell({
    children,
    className,
    title,
}: {
    children: ReactNode;
    className?: string;
    title?: string;
}) {
    return (
        <TableCell
            className={cn(dataTableCellClass(), 'align-top', className)}
            title={title}
        >
            {children}
        </TableCell>
    );
}

const groupClass =
    'sticky top-0 z-20 h-8 border-r bg-muted/95 text-center text-[10px] font-bold tracking-wider uppercase backdrop-blur';
const headerClass = 'sticky top-8 z-20 bg-background/95 backdrop-blur';

export function CrewMovementHistoryReportTable({
    rows,
    filters,
    onSort,
}: {
    rows: CrewMovementHistoryRow[];
    filters: CrewMovementHistoryFilters;
    onSort: (column: string) => void;
}) {
    return (
        <OrganizationDataTable
            minWidth="min-w-[6700px]"
            compact
            tableClassName="table-fixed"
        >
            <TableHeader>
                <TableRow>
                    <DataTableHead colSpan={10} className={groupClass}>
                        Identity
                    </DataTableHead>
                    <DataTableHead colSpan={4} className={groupClass}>
                        Planning
                    </DataTableHead>
                    <DataTableHead colSpan={3} className={groupClass}>
                        Pre-Mobilisation
                    </DataTableHead>
                    <DataTableHead colSpan={3} className={groupClass}>
                        Travel In
                    </DataTableHead>
                    <DataTableHead colSpan={2} className={groupClass}>
                        Join Standby
                    </DataTableHead>
                    <DataTableHead colSpan={3} className={groupClass}>
                        Training
                    </DataTableHead>
                    <DataTableHead colSpan={4} className={groupClass}>
                        Ready
                    </DataTableHead>
                    <DataTableHead colSpan={4} className={groupClass}>
                        On Vessel
                    </DataTableHead>
                    <DataTableHead colSpan={4} className={groupClass}>
                        Demob Standby
                    </DataTableHead>
                    <DataTableHead colSpan={4} className={groupClass}>
                        Home / Redeploy
                    </DataTableHead>
                    <DataTableHead colSpan={5} className={groupClass}>
                        Completion
                    </DataTableHead>
                    <DataTableHead rowSpan={2} className={groupClass}>
                        Actions
                    </DataTableHead>
                </TableRow>
                <TableRow>
                    <SortHead
                        column="assignment_no"
                        label="Assignment No"
                        filters={filters}
                        onSort={onSort}
                        className={cn(
                            headerClass,
                            'sticky left-0 z-30 w-[150px]',
                        )}
                    />
                    <SortHead
                        label="Employee No"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[130px]')}
                    />
                    <SortHead
                        column="employee_name"
                        label="Employee Name"
                        filters={filters}
                        onSort={onSort}
                        className={cn(
                            headerClass,
                            'sticky left-[150px] z-30 w-[220px]',
                        )}
                    />
                    <SortHead
                        column="rank"
                        label="Rank"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[140px]')}
                    />
                    <SortHead
                        column="vessel"
                        label="Vessel"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[160px]')}
                    />
                    <SortHead
                        column="client"
                        label="Client"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[150px]')}
                    />
                    <SortHead
                        label="Sponsor / Visa Type"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[170px]')}
                    />
                    <SortHead
                        column="status"
                        label="Status"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[120px]')}
                    />
                    <SortHead
                        label="Current Phase"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[150px]')}
                    />
                    <SortHead
                        label="Source"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[130px]')}
                    />
                    {[
                        'Planned Travel In',
                        'Planned Join',
                        'Planned Sign-Off',
                        'Planned Travel Home',
                    ].map((label) => (
                        <SortHead
                            key={label}
                            column={
                                label === 'Planned Join'
                                    ? 'planned_join'
                                    : label === 'Planned Sign-Off'
                                      ? 'planned_signoff'
                                      : undefined
                            }
                            label={label}
                            filters={filters}
                            onSort={onSort}
                            className={cn(headerClass, 'w-[140px]')}
                        />
                    ))}
                    {['From', 'To', 'Days', 'From', 'Arrival Date', 'Days'].map(
                        (label, index) => (
                            <SortHead
                                key={`${label}-${index}`}
                                label={label}
                                filters={filters}
                                onSort={onSort}
                                className={cn(headerClass, 'w-[125px]')}
                            />
                        ),
                    )}
                    <SortHead
                        label="Periods"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[230px]')}
                    />
                    <SortHead
                        label="Total Days"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[110px]')}
                    />
                    <SortHead
                        label="Periods"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[230px]')}
                    />
                    <SortHead
                        label="Total Days"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[110px]')}
                    />
                    <SortHead
                        label="Provider / Course"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[220px]')}
                    />
                    <SortHead
                        label="Periods"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[230px]')}
                    />
                    <SortHead
                        label="From"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[125px]')}
                    />
                    <SortHead
                        label="To"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[125px]')}
                    />
                    <SortHead
                        label="Days"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[120px]')}
                    />
                    <SortHead
                        label="On-Vessel Periods"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[230px]')}
                    />
                    <SortHead
                        label="Actual Join"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[130px]')}
                    />
                    <SortHead
                        label="Actual Disembarkation"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[170px]')}
                    />
                    <SortHead
                        label="Vessel Days"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[120px]')}
                    />
                    {[
                        'Periods',
                        'From',
                        'To',
                        'Days',
                        'Periods',
                        'From',
                        'To',
                        'Days',
                    ].map((label, index) => (
                        <SortHead
                            key={`${label}-${index}`}
                            label={label}
                            filters={filters}
                            onSort={onSort}
                            className={cn(
                                headerClass,
                                index % 4 === 0 ? 'w-[220px]' : 'w-[125px]',
                            )}
                        />
                    ))}
                    <SortHead
                        column="started_at"
                        label="Assignment Started"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[150px]')}
                    />
                    <SortHead
                        column="closed_at"
                        label="Assignment Closed"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[150px]')}
                    />
                    <SortHead
                        label="Total Days"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[120px]')}
                    />
                    <SortHead
                        label="Remarks"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[240px]')}
                    />
                    <SortHead
                        label="Needs Attention"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, 'w-[180px]')}
                    />
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => {
                    const p0Ongoing = row.pre_mobilisation.periods.some(
                        (period) => period.status === 'active',
                    );
                    const p1Ongoing = row.travel_in.periods.some(
                        (period) => period.status === 'active',
                    );
                    const readyOngoing = row.ready_to_join.periods.some(
                        (period) => period.status === 'active',
                    );
                    const vesselOngoing = row.on_vessel.periods.some(
                        (period) => period.status === 'active',
                    );
                    const demobOngoing = row.demob_standby.periods.some(
                        (period) => period.status === 'active',
                    );
                    const homeOngoing = row.home_redeploy.periods.some(
                        (period) => period.status === 'active',
                    );

                    return (
                        <TableRow
                            key={row.id}
                            className={cn(
                                dataTableBodyRowClass(false),
                                'group',
                            )}
                        >
                            <Cell className="sticky left-0 z-10 bg-background font-semibold group-hover:bg-muted">
                                <Link
                                    href={showAssignment.url(row.id)}
                                    className="text-primary hover:underline"
                                >
                                    {row.assignment_no}
                                </Link>
                            </Cell>
                            <Cell>{row.employee.employee_no ?? '—'}</Cell>
                            <Cell className="sticky left-[150px] z-10 bg-background font-medium group-hover:bg-muted">
                                {row.employee.name ?? '—'}
                            </Cell>
                            <Cell>{row.rank?.name ?? '—'}</Cell>
                            <Cell>{row.vessel?.name ?? '—'}</Cell>
                            <Cell>{row.client?.name ?? '—'}</Cell>
                            <Cell>{row.visa_type?.name ?? '—'}</Cell>
                            <Cell>{row.status_label}</Cell>
                            <Cell>{row.current_phase?.label ?? '—'}</Cell>
                            <Cell>{row.source_label}</Cell>
                            <Cell>
                                <DateCell value={row.planned_travel_in} />
                            </Cell>
                            <Cell>
                                <DateCell value={row.planned_join} />
                            </Cell>
                            <Cell>
                                <DateCell value={row.planned_signoff} />
                            </Cell>
                            <Cell>
                                <DateCell value={row.planned_travel_home} />
                            </Cell>
                            <Cell>
                                <DateCell value={row.pre_mobilisation.from} />
                            </Cell>
                            <Cell>
                                <DateCell
                                    value={row.pre_mobilisation.to}
                                    ongoing={p0Ongoing}
                                />
                            </Cell>
                            <Cell>{row.pre_mobilisation.total_days_label}</Cell>
                            <Cell>
                                <DateCell value={row.travel_in.from} />
                            </Cell>
                            <Cell>
                                <DateCell
                                    value={row.travel_in.to}
                                    ongoing={p1Ongoing}
                                />
                            </Cell>
                            <Cell>{row.travel_in.total_days_label}</Cell>
                            <Cell>
                                <Periods summary={row.join_standby} />
                            </Cell>
                            <Cell>{row.join_standby.total_days_label}</Cell>
                            <Cell>
                                <Periods summary={row.training} />
                            </Cell>
                            <Cell>{row.training.total_days_label}</Cell>
                            <Cell title={row.training.details.join('\n')}>
                                {row.training.details.length
                                    ? row.training.details.join('; ')
                                    : '—'}
                            </Cell>
                            <Cell>
                                <Periods summary={row.ready_to_join} />
                            </Cell>
                            <Cell>
                                <DateCell value={row.ready_to_join.from} />
                            </Cell>
                            <Cell>
                                <DateCell
                                    value={row.ready_to_join.to}
                                    ongoing={readyOngoing}
                                />
                            </Cell>
                            <Cell>{row.ready_to_join.total_days_label}</Cell>
                            <Cell>
                                <Periods summary={row.on_vessel} />
                            </Cell>
                            <Cell>
                                <DateCell value={row.on_vessel.actual_join} />
                            </Cell>
                            <Cell>
                                <DateCell
                                    value={row.on_vessel.actual_disembarkation}
                                    ongoing={vesselOngoing}
                                />
                            </Cell>
                            <Cell>{row.on_vessel.total_days_label}</Cell>
                            <Cell>
                                <Periods summary={row.demob_standby} />
                            </Cell>
                            <Cell>
                                <DateCell value={row.demob_standby.from} />
                            </Cell>
                            <Cell>
                                <DateCell
                                    value={row.demob_standby.to}
                                    ongoing={demobOngoing}
                                />
                            </Cell>
                            <Cell>{row.demob_standby.total_days_label}</Cell>
                            <Cell>
                                <Periods summary={row.home_redeploy} />
                            </Cell>
                            <Cell>
                                <DateCell value={row.home_redeploy.from} />
                            </Cell>
                            <Cell>
                                <DateCell
                                    value={row.home_redeploy.to}
                                    ongoing={homeOngoing}
                                />
                            </Cell>
                            <Cell>{row.home_redeploy.total_days_label}</Cell>
                            <Cell>
                                <DateCell value={row.assignment_started} />
                            </Cell>
                            <Cell>
                                <DateCell
                                    value={row.assignment_closed}
                                    ongoing={row.status === 'active'}
                                />
                            </Cell>
                            <Cell>{row.total_assignment_days_label}</Cell>
                            <Cell
                                className="whitespace-normal"
                                title={row.remarks ?? undefined}
                            >
                                {row.remarks ?? '—'}
                            </Cell>
                            <Cell
                                className="whitespace-normal"
                                title={row.warnings.join('\n')}
                            >
                                {row.needs_attention
                                    ? row.warnings.join(', ')
                                    : 'No'}
                            </Cell>
                            <Cell>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Actions for ${row.assignment_no}`}
                                        >
                                            <MoreHorizontal className="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem asChild>
                                            <Link
                                                href={showAssignment.url(
                                                    row.id,
                                                )}
                                            >
                                                View Assignment
                                            </Link>
                                        </DropdownMenuItem>
                                        {row.employee.id ? (
                                            <DropdownMenuItem asChild>
                                                <Link
                                                    href={showEmployee.url(
                                                        row.employee.id,
                                                    )}
                                                >
                                                    View Employee
                                                </Link>
                                            </DropdownMenuItem>
                                        ) : null}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </Cell>
                        </TableRow>
                    );
                })}
            </TableBody>
        </OrganizationDataTable>
    );
}
