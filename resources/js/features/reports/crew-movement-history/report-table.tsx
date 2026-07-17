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
import { Badge } from '@/components/ui/badge';
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

const COL = {
    assignment: 'w-[148px] min-w-[148px] max-w-[148px]',
    employeeName: 'w-[240px] min-w-[240px] max-w-[240px]',
    rank: 'w-[140px] min-w-[140px]',
    vessel: 'w-[160px] min-w-[160px]',
    client: 'w-[150px] min-w-[150px]',
    visa: 'w-[160px] min-w-[160px]',
    phase: 'w-[140px] min-w-[140px]',
    date: 'w-[120px] min-w-[120px]',
    days: 'w-[100px] min-w-[100px]',
    periods: 'w-[200px] min-w-[200px]',
    details: 'w-[200px] min-w-[200px]',
    remarks: 'w-[220px] min-w-[220px]',
    attention: 'w-[170px] min-w-[170px]',
    actions: 'w-[72px] min-w-[72px]',
} as const;

const stickyHead = 'sticky top-8 z-30 border-r border-border/70 bg-background';
const stickyCell =
    'sticky z-10 border-r border-border/70 bg-background group-hover:bg-muted';
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
        return (
            <DataTableHead className={cn('whitespace-nowrap', className)}>
                {label}
            </DataTableHead>
        );
    }

    const active = filters.sort === column;
    const Icon = active
        ? filters.direction === 'asc'
            ? ArrowUp
            : ArrowDown
        : ChevronsUpDown;

    return (
        <DataTableHead className={cn('whitespace-nowrap', className)}>
            <button
                type="button"
                onClick={() => onSort(column)}
                className="inline-flex max-w-full items-center gap-1 truncate"
            >
                <span className="truncate">{label}</span>
                <Icon className="size-3.5 shrink-0" aria-hidden />
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
        <div className="space-y-1 whitespace-normal" title={text}>
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
        <div className="text-xs leading-snug">
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
            className={cn(
                dataTableCellClass(),
                'align-top whitespace-nowrap',
                className,
            )}
            title={title}
        >
            {children}
        </TableCell>
    );
}

function Truncate({
    children,
    title,
}: {
    children: ReactNode;
    title?: string | null;
}) {
    return (
        <span className="block truncate" title={title ?? undefined}>
            {children}
        </span>
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
            minWidth="min-w-[5960px]"
            compact
            tableClassName="table-fixed"
        >
            <TableHeader>
                <TableRow>
                    <DataTableHead colSpan={7} className={groupClass}>
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
                    <DataTableHead
                        rowSpan={2}
                        className={cn(groupClass, COL.actions, 'align-middle')}
                    >
                        Actions
                    </DataTableHead>
                </TableRow>
                <TableRow>
                    <SortHead
                        column="assignment_no"
                        label="Assignment No"
                        filters={filters}
                        onSort={onSort}
                        className={cn(stickyHead, COL.assignment, 'left-0')}
                    />
                    <SortHead
                        column="employee_name"
                        label="Employee"
                        filters={filters}
                        onSort={onSort}
                        className={cn(
                            stickyHead,
                            COL.employeeName,
                            'left-[148px]',
                        )}
                    />
                    <SortHead
                        column="rank"
                        label="Rank"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.rank)}
                    />
                    <SortHead
                        column="vessel"
                        label="Vessel"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.vessel)}
                    />
                    <SortHead
                        column="client"
                        label="Client"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.client)}
                    />
                    <SortHead
                        label="Sponsor / Visa Type"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.visa)}
                    />
                    <SortHead
                        label="Current Phase"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.phase)}
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
                            className={cn(headerClass, COL.date)}
                        />
                    ))}
                    {['From', 'To', 'Days', 'From', 'Arrival Date', 'Days'].map(
                        (label, index) => (
                            <SortHead
                                key={`${label}-${index}`}
                                label={label}
                                filters={filters}
                                onSort={onSort}
                                className={cn(
                                    headerClass,
                                    label === 'Days' ? COL.days : COL.date,
                                )}
                            />
                        ),
                    )}
                    <SortHead
                        label="Periods"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.periods)}
                    />
                    <SortHead
                        label="Total Days"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.days)}
                    />
                    <SortHead
                        label="Periods"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.periods)}
                    />
                    <SortHead
                        label="Total Days"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.days)}
                    />
                    <SortHead
                        label="Provider / Course"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.details)}
                    />
                    <SortHead
                        label="Periods"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.periods)}
                    />
                    <SortHead
                        label="From"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.date)}
                    />
                    <SortHead
                        label="To"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.date)}
                    />
                    <SortHead
                        label="Days"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.days)}
                    />
                    <SortHead
                        label="On-Vessel Periods"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.periods)}
                    />
                    <SortHead
                        label="Actual Join"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.date)}
                    />
                    <SortHead
                        label="Actual Disembarkation"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.date)}
                    />
                    <SortHead
                        label="Vessel Days"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.days)}
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
                                label === 'Periods'
                                    ? COL.periods
                                    : label === 'Days'
                                      ? COL.days
                                      : COL.date,
                            )}
                        />
                    ))}
                    <SortHead
                        column="started_at"
                        label="Assignment Started"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.date)}
                    />
                    <SortHead
                        column="closed_at"
                        label="Assignment Closed"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.date)}
                    />
                    <SortHead
                        label="Total Days"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.days)}
                    />
                    <SortHead
                        label="Remarks"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.remarks)}
                    />
                    <SortHead
                        label="Needs Attention"
                        filters={filters}
                        onSort={onSort}
                        className={cn(headerClass, COL.attention)}
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
                            <Cell
                                className={cn(
                                    stickyCell,
                                    COL.assignment,
                                    'left-0 font-semibold',
                                )}
                            >
                                <Truncate title={row.assignment_no}>
                                    <Link
                                        href={showAssignment.url(row.id)}
                                        className="text-primary hover:underline"
                                    >
                                        {row.assignment_no}
                                    </Link>
                                </Truncate>
                            </Cell>
                            <Cell
                                className={cn(
                                    stickyCell,
                                    COL.employeeName,
                                    'left-[148px]',
                                )}
                            >
                                <div
                                    className="min-w-0 space-y-1.5"
                                    title={
                                        [
                                            row.employee.name,
                                            row.employee.employee_no,
                                            row.status_label,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || undefined
                                    }
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium text-foreground">
                                            {row.employee.name ?? '—'}
                                        </p>
                                        {row.employee.employee_no ? (
                                            <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                                                {row.employee.employee_no}
                                            </p>
                                        ) : null}
                                    </div>
                                    <Badge
                                        variant={
                                            row.status === 'active'
                                                ? 'success'
                                                : row.status === 'draft'
                                                  ? 'secondary'
                                                  : row.status === 'cancelled'
                                                    ? 'destructive'
                                                    : 'outline'
                                        }
                                    >
                                        {row.status_label}
                                    </Badge>
                                </div>
                            </Cell>
                            <Cell className={COL.rank}>
                                <Truncate title={row.rank?.name}>
                                    {row.rank?.name ?? '—'}
                                </Truncate>
                            </Cell>
                            <Cell className={COL.vessel}>
                                <Truncate title={row.vessel?.name}>
                                    {row.vessel?.name ?? '—'}
                                </Truncate>
                            </Cell>
                            <Cell className={COL.client}>
                                <Truncate title={row.client?.name}>
                                    {row.client?.name ?? '—'}
                                </Truncate>
                            </Cell>
                            <Cell className={COL.visa}>
                                <Truncate title={row.visa_type?.name}>
                                    {row.visa_type?.name ?? '—'}
                                </Truncate>
                            </Cell>
                            <Cell className={COL.phase}>
                                <Truncate title={row.current_phase?.label}>
                                    {row.current_phase?.label ?? '—'}
                                </Truncate>
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.planned_travel_in} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.planned_join} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.planned_signoff} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.planned_travel_home} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.pre_mobilisation.from} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell
                                    value={row.pre_mobilisation.to}
                                    ongoing={p0Ongoing}
                                />
                            </Cell>
                            <Cell className={COL.days}>
                                {row.pre_mobilisation.total_days_label}
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.travel_in.from} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell
                                    value={row.travel_in.to}
                                    ongoing={p1Ongoing}
                                />
                            </Cell>
                            <Cell className={COL.days}>
                                {row.travel_in.total_days_label}
                            </Cell>
                            <Cell
                                className={cn(COL.periods, 'whitespace-normal')}
                            >
                                <Periods summary={row.join_standby} />
                            </Cell>
                            <Cell className={COL.days}>
                                {row.join_standby.total_days_label}
                            </Cell>
                            <Cell
                                className={cn(COL.periods, 'whitespace-normal')}
                            >
                                <Periods summary={row.training} />
                            </Cell>
                            <Cell className={COL.days}>
                                {row.training.total_days_label}
                            </Cell>
                            <Cell
                                className={cn(COL.details, 'whitespace-normal')}
                                title={row.training.details.join('\n')}
                            >
                                {row.training.details.length
                                    ? row.training.details.join('; ')
                                    : '—'}
                            </Cell>
                            <Cell
                                className={cn(COL.periods, 'whitespace-normal')}
                            >
                                <Periods summary={row.ready_to_join} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.ready_to_join.from} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell
                                    value={row.ready_to_join.to}
                                    ongoing={readyOngoing}
                                />
                            </Cell>
                            <Cell className={COL.days}>
                                {row.ready_to_join.total_days_label}
                            </Cell>
                            <Cell
                                className={cn(COL.periods, 'whitespace-normal')}
                            >
                                <Periods summary={row.on_vessel} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.on_vessel.actual_join} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell
                                    value={row.on_vessel.actual_disembarkation}
                                    ongoing={vesselOngoing}
                                />
                            </Cell>
                            <Cell className={COL.days}>
                                {row.on_vessel.total_days_label}
                            </Cell>
                            <Cell
                                className={cn(COL.periods, 'whitespace-normal')}
                            >
                                <Periods summary={row.demob_standby} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.demob_standby.from} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell
                                    value={row.demob_standby.to}
                                    ongoing={demobOngoing}
                                />
                            </Cell>
                            <Cell className={COL.days}>
                                {row.demob_standby.total_days_label}
                            </Cell>
                            <Cell
                                className={cn(COL.periods, 'whitespace-normal')}
                            >
                                <Periods summary={row.home_redeploy} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.home_redeploy.from} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell
                                    value={row.home_redeploy.to}
                                    ongoing={homeOngoing}
                                />
                            </Cell>
                            <Cell className={COL.days}>
                                {row.home_redeploy.total_days_label}
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell value={row.assignment_started} />
                            </Cell>
                            <Cell className={COL.date}>
                                <DateCell
                                    value={row.assignment_closed}
                                    ongoing={row.status === 'active'}
                                />
                            </Cell>
                            <Cell className={COL.days}>
                                {row.total_assignment_days_label}
                            </Cell>
                            <Cell
                                className={cn(COL.remarks, 'whitespace-normal')}
                                title={row.remarks ?? undefined}
                            >
                                {row.remarks ?? '—'}
                            </Cell>
                            <Cell
                                className={cn(
                                    COL.attention,
                                    'whitespace-normal',
                                )}
                                title={row.warnings.join('\n')}
                            >
                                {row.needs_attention
                                    ? row.warnings.join(', ')
                                    : 'No'}
                            </Cell>
                            <Cell className={COL.actions}>
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
