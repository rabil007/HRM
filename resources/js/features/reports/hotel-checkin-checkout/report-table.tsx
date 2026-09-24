import { Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronDown,
    ChevronRight,
    ExternalLink,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { cn } from '@/lib/utils';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import type {
    HotelCheckInCheckoutFilters,
    HotelCheckInCheckoutRow,
} from './types';

function formatOperationalDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value.trim());

    if (!match) {
        return value;
    }

    const [, y, m, d] = match;
    const date = new Date(Number(y), Number(m) - 1, Number(d));

    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function stayStatusBadgeClass(status: string): string {
    switch (status) {
        case 'currently_checked_in':
            return 'border-blue-500/30 bg-blue-500/10 text-blue-700 dark:text-blue-400';
        case 'check_in_today':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';
        case 'checking_out_today':
            return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400';
        case 'upcoming':
            return 'border-indigo-500/30 bg-indigo-500/10 text-indigo-700 dark:text-indigo-400';
        case 'checked_out':
            return 'border-slate-500/30 bg-slate-500/10 text-slate-700 dark:text-slate-400';
        default:
            return 'border-border text-muted-foreground';
    }
}

function stayTypeBadgeClass(type: string | null): string {
    switch (type) {
        case 'pre_join':
            return 'border-cyan-500/30 bg-cyan-500/10 text-cyan-700 dark:text-cyan-400';
        case 'post_signoff':
            return 'border-purple-500/30 bg-purple-500/10 text-purple-700 dark:text-purple-400';
        default:
            return 'border-border text-muted-foreground';
    }
}

function SortHeader({
    label,
    column,
    activeSort,
    direction,
    onSort,
}: {
    label: string;
    column: string;
    activeSort: string;
    direction: 'asc' | 'desc';
    onSort: (column: string) => void;
}) {
    const isActive = activeSort === column;

    return (
        <button
            type="button"
            onClick={() => onSort(column)}
            className="flex items-center gap-1.5 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase transition-colors hover:text-foreground focus-visible:outline-none"
        >
            <span>{label}</span>
            {isActive ? (
                direction === 'asc' ? (
                    <ArrowUp className="size-3.5 text-primary" />
                ) : (
                    <ArrowDown className="size-3.5 text-primary" />
                )
            ) : (
                <ArrowUpDown className="size-3.5 opacity-40" />
            )}
        </button>
    );
}

function DetailRow({
    label,
    value,
}: {
    label: string;
    value: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-0.5 border-b border-border/40 py-1.5 text-xs sm:flex-row sm:items-baseline sm:justify-between">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-medium text-foreground">{value ?? '—'}</span>
        </div>
    );
}

export function HotelCheckInCheckoutReportTable({
    rows,
    filters,
    onSort,
}: {
    rows: HotelCheckInCheckoutRow[];
    filters: HotelCheckInCheckoutFilters;
    onSort: (column: string) => void;
}) {
    const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set());

    const toggle = (id: number): void => {
        setExpandedIds((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    return (
        <div className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-xs">
            <Table>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="w-10 px-3" />
                        <TableHead>
                            <SortHeader
                                label="Employee"
                                column="employee"
                                activeSort={filters.sort}
                                direction={filters.direction}
                                onSort={onSort}
                            />
                        </TableHead>
                        <TableHead>Rank</TableHead>
                        <TableHead>
                            <SortHeader
                                label="Hotel"
                                column="hotel"
                                activeSort={filters.sort}
                                direction={filters.direction}
                                onSort={onSort}
                            />
                        </TableHead>
                        <TableHead>Room Type</TableHead>
                        <TableHead>Stay Type</TableHead>
                        <TableHead>
                            <SortHeader
                                label="Check-In"
                                column="check_in"
                                activeSort={filters.sort}
                                direction={filters.direction}
                                onSort={onSort}
                            />
                        </TableHead>
                        <TableHead>
                            <SortHeader
                                label="Check-Out"
                                column="check_out"
                                activeSort={filters.sort}
                                direction={filters.direction}
                                onSort={onSort}
                            />
                        </TableHead>
                        <TableHead>Stay Status</TableHead>
                        <TableHead className="text-right">
                            <SortHeader
                                label="Stay Days"
                                column="stay_days"
                                activeSort={filters.sort}
                                direction={filters.direction}
                                onSort={onSort}
                            />
                        </TableHead>
                        <TableHead>
                            <SortHeader
                                label="Vessel"
                                column="vessel"
                                activeSort={filters.sort}
                                direction={filters.direction}
                                onSort={onSort}
                            />
                        </TableHead>
                        <TableHead>Crew Assignment</TableHead>
                        <TableHead>Starting Checkpoint</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => {
                        const isExpanded = expandedIds.has(row.id);

                        return (
                            <Fragment key={row.id}>
                                <TableRow
                                    className={cn(
                                        'cursor-pointer transition-colors',
                                        isExpanded && 'bg-muted/30',
                                    )}
                                    onClick={() => toggle(row.id)}
                                >
                                    <TableCell className="px-3">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-6 text-muted-foreground"
                                            aria-label={
                                                isExpanded
                                                    ? 'Collapse row'
                                                    : 'Expand row'
                                            }
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                toggle(row.id);
                                            }}
                                        >
                                            {isExpanded ? (
                                                <ChevronDown className="size-4" />
                                            ) : (
                                                <ChevronRight className="size-4" />
                                            )}
                                        </Button>
                                    </TableCell>
                                    <TableCell>
                                        <div className="min-w-0 space-y-0.5">
                                            {row.employee.id ? (
                                                <EmployeeProfileLink
                                                    employeeId={row.employee.id}
                                                    className="font-medium text-foreground hover:text-primary hover:underline"
                                                    stopRowNavigation={true}
                                                >
                                                    {row.employee.name}
                                                </EmployeeProfileLink>
                                            ) : (
                                                <span className="font-medium text-foreground">
                                                    {row.employee.name}
                                                </span>
                                            )}
                                            <span className="block font-mono text-[11px] text-muted-foreground">
                                                {row.employee.employee_no}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                                        {row.assignment.rank_name}
                                    </TableCell>
                                    <TableCell className="text-xs font-medium whitespace-nowrap">
                                        {row.hotel.name}
                                    </TableCell>
                                    <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                                        {row.room_type.name}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        <Badge
                                            variant="outline"
                                            className={cn(
                                                'px-1.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase',
                                                stayTypeBadgeClass(
                                                    row.stay_type,
                                                ),
                                            )}
                                        >
                                            {row.stay_type_label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs whitespace-nowrap">
                                        {formatOperationalDate(
                                            row.check_in_date,
                                        )}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs whitespace-nowrap">
                                        {row.is_open ? (
                                            <Badge
                                                variant="outline"
                                                className="border-emerald-500/30 bg-emerald-500/10 font-mono text-[11px] font-semibold text-emerald-700 dark:text-emerald-400"
                                            >
                                                Open
                                            </Badge>
                                        ) : (
                                            formatOperationalDate(
                                                row.check_out_date,
                                            )
                                        )}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        <Badge
                                            variant="outline"
                                            className={cn(
                                                'px-2 py-0.5 text-[11px] font-medium',
                                                stayStatusBadgeClass(
                                                    row.stay_status,
                                                ),
                                            )}
                                        >
                                            {row.stay_status_label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right font-mono text-xs font-semibold whitespace-nowrap tabular-nums">
                                        {row.stay_days !== null
                                            ? `${row.stay_days} d`
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                                        {row.assignment.vessel_name}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {row.assignment.id ? (
                                            <Link
                                                href={showAssignment.url(
                                                    row.assignment.id,
                                                )}
                                                className="inline-flex items-center gap-1 font-mono text-xs text-primary hover:underline"
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <span>
                                                    {
                                                        row.assignment
                                                            .assignment_no
                                                    }
                                                </span>
                                                <ExternalLink className="size-3 opacity-70" />
                                            </Link>
                                        ) : (
                                            <span className="font-mono text-xs">
                                                {row.assignment.assignment_no}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                                        {row.starting_checkpoint ?? '—'}
                                    </TableCell>
                                </TableRow>

                                {isExpanded ? (
                                    <TableRow className="bg-muted/20 hover:bg-muted/20">
                                        <TableCell
                                            colSpan={13}
                                            className="p-4 sm:p-6"
                                        >
                                            <div className="grid grid-cols-1 gap-6 rounded-lg border border-border/60 bg-card p-4 shadow-2xs md:grid-cols-3">
                                                {/* Crew Column */}
                                                <div className="space-y-1">
                                                    <h4 className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Crew Details
                                                    </h4>
                                                    <DetailRow
                                                        label="Employee Name"
                                                        value={
                                                            row.employee.id ? (
                                                                <EmployeeProfileLink
                                                                    employeeId={
                                                                        row
                                                                            .employee
                                                                            .id
                                                                    }
                                                                    className="text-primary hover:underline"
                                                                >
                                                                    {
                                                                        row
                                                                            .employee
                                                                            .name
                                                                    }
                                                                </EmployeeProfileLink>
                                                            ) : (
                                                                row.employee
                                                                    .name
                                                            )
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Employee No."
                                                        value={
                                                            row.employee
                                                                .employee_no
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Rank"
                                                        value={
                                                            row.assignment
                                                                .rank_name
                                                        }
                                                    />
                                                </div>

                                                {/* Accommodation Column */}
                                                <div className="space-y-1">
                                                    <h4 className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Accommodation Details
                                                    </h4>
                                                    <DetailRow
                                                        label="Stay Type"
                                                        value={
                                                            row.stay_type_label
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Accommodation Status"
                                                        value={
                                                            row.accommodation_status_label
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Hotel"
                                                        value={row.hotel.name}
                                                    />
                                                    <DetailRow
                                                        label="Room Type"
                                                        value={
                                                            row.room_type.name
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Check-In"
                                                        value={formatOperationalDate(
                                                            row.check_in_date,
                                                        )}
                                                    />
                                                    <DetailRow
                                                        label="Check-Out"
                                                        value={
                                                            row.is_open
                                                                ? 'Open'
                                                                : formatOperationalDate(
                                                                      row.check_out_date,
                                                                  )
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Stay Days"
                                                        value={
                                                            row.stay_days !==
                                                            null
                                                                ? `${row.stay_days} days`
                                                                : '—'
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Operational Status"
                                                        value={
                                                            row.stay_status_label
                                                        }
                                                    />
                                                </div>

                                                {/* Assignment Column */}
                                                <div className="space-y-1">
                                                    <h4 className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Assignment Context
                                                    </h4>
                                                    <DetailRow
                                                        label="Assignment No."
                                                        value={
                                                            row.assignment
                                                                .id ? (
                                                                <Link
                                                                    href={showAssignment.url(
                                                                        row
                                                                            .assignment
                                                                            .id,
                                                                    )}
                                                                    className="inline-flex items-center gap-1 font-mono text-primary hover:underline"
                                                                >
                                                                    <span>
                                                                        {
                                                                            row
                                                                                .assignment
                                                                                .assignment_no
                                                                        }
                                                                    </span>
                                                                    <ExternalLink className="size-3" />
                                                                </Link>
                                                            ) : (
                                                                row.assignment
                                                                    .assignment_no
                                                            )
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Assignment Status"
                                                        value={
                                                            row.assignment
                                                                .status_label
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Vessel"
                                                        value={
                                                            row.assignment
                                                                .vessel_name
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Client"
                                                        value={
                                                            row.assignment
                                                                .client_name
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Current Phase"
                                                        value={
                                                            row.current_phase ??
                                                            '—'
                                                        }
                                                    />
                                                    <DetailRow
                                                        label="Starting Checkpoint"
                                                        value={
                                                            row.starting_checkpoint ??
                                                            '—'
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
