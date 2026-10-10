import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronsUpDown, ExternalLink } from 'lucide-react';
import {
    DataTableHead,
    OrganizationDataTable,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { CandidateStageBadge } from '@/features/organization/recruitment/candidates/components/candidate-stage-badge';
import { cn } from '@/lib/utils';
import { show as candidateShow } from '@/routes/organization/recruitment/candidates';
import type {
    RecruitmentCandidateRow,
    RecruitmentReportFilters,
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
    filters: RecruitmentReportFilters;
    onSort: (col: string) => void;
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

    return (
        <DataTableHead className={cn('whitespace-nowrap', className)}>
            <button
                type="button"
                onClick={() => onSort(column)}
                className="inline-flex items-center gap-1 font-semibold hover:text-foreground"
            >
                {label}
                {active ? (
                    filters.direction === 'asc' ? (
                        <ArrowUp className="size-3.5" />
                    ) : (
                        <ArrowDown className="size-3.5" />
                    )
                ) : (
                    <ChevronsUpDown className="size-3.5 opacity-40" />
                )}
            </button>
        </DataTableHead>
    );
}

function ConversionBadge({
    status,
    label,
}: {
    status: RecruitmentCandidateRow['conversion_status'];
    label: string;
}) {
    if (status === 'converted') {
        return (
            <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">
                {label}
            </Badge>
        );
    }

    if (status === 'pending') {
        return <Badge variant="outline">{label}</Badge>;
    }

    return <span className="text-sm text-muted-foreground">—</span>;
}

function DurationCell({ days }: { days: number | null }) {
    if (days === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className="tabular-nums">
            {days}
            <span className="ml-1 text-xs text-muted-foreground">days</span>
        </span>
    );
}

export function RecruitmentReportTable({
    rows,
    filters,
    onSort,
    canViewCandidates,
    canViewEmployees,
}: {
    rows: RecruitmentCandidateRow[];
    filters: RecruitmentReportFilters;
    onSort: (col: string) => void;
    canViewCandidates: boolean;
    canViewEmployees: boolean;
}) {
    return (
        <OrganizationDataTable>
            <TableHeader>
                <TableRow>
                    <SortHead
                        column="name"
                        label="Candidate"
                        filters={filters}
                        onSort={onSort}
                        className="min-w-[12rem]"
                    />
                    <DataTableHead className="min-w-[8rem]">
                        Requirement
                    </DataTableHead>
                    <DataTableHead>Client</DataTableHead>
                    <DataTableHead>Position</DataTableHead>
                    <DataTableHead>Recruiter</DataTableHead>
                    <SortHead
                        column="stage"
                        label="Stage"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        column="expected_joining_date"
                        label="Exp. Joining"
                        filters={filters}
                        onSort={onSort}
                        className="whitespace-nowrap"
                    />
                    <SortHead
                        column="actual_joining_date"
                        label="Actual Joining"
                        filters={filters}
                        onSort={onSort}
                        className="whitespace-nowrap"
                    />
                    <DataTableHead>Conversion</DataTableHead>
                    <DataTableHead>Time to Hire</DataTableHead>
                    <SortHead
                        column="created_at"
                        label="Applied"
                        filters={filters}
                        onSort={onSort}
                        className="whitespace-nowrap"
                    />
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow
                        key={row.id}
                        className={dataTableBodyRowClass(false)}
                    >
                        {/* Candidate name */}
                        <TableCell
                            className={cn(
                                dataTableCellClass(),
                                dataTableCellPrimaryClass(),
                            )}
                        >
                            <div className="flex flex-col gap-0.5">
                                {canViewCandidates ? (
                                    <Link
                                        href={candidateShow.url({
                                            candidate: row.id,
                                        })}
                                        className="inline-flex items-center gap-1 font-medium hover:underline"
                                    >
                                        {row.name}
                                        <ExternalLink className="size-3 opacity-50" />
                                    </Link>
                                ) : (
                                    <span className="font-medium">
                                        {row.name}
                                    </span>
                                )}
                                {row.nationality ? (
                                    <span className="text-xs text-muted-foreground">
                                        {row.nationality}
                                    </span>
                                ) : null}
                            </div>
                        </TableCell>

                        {/* Requirement */}
                        <TableCell className={dataTableCellClass()}>
                            <span className="font-mono text-xs">
                                {row.requirement.requirement_number}
                            </span>
                        </TableCell>

                        {/* Client */}
                        <TableCell
                            className={cn(
                                dataTableCellClass(),
                                'whitespace-nowrap',
                            )}
                        >
                            {row.client_name ?? (
                                <span className="text-muted-foreground">—</span>
                            )}
                        </TableCell>

                        {/* Position */}
                        <TableCell
                            className={cn(
                                dataTableCellClass(),
                                'whitespace-nowrap',
                            )}
                        >
                            {row.position_title}
                        </TableCell>

                        {/* Recruiter */}
                        <TableCell className={dataTableCellClass()}>
                            {row.recruiter_name ?? (
                                <span className="text-muted-foreground">—</span>
                            )}
                        </TableCell>

                        {/* Stage */}
                        <TableCell className={dataTableCellClass()}>
                            <CandidateStageBadge
                                stage={row.stage as any}
                                label={row.stage_label}
                            />
                        </TableCell>

                        {/* Expected joining */}
                        <TableCell
                            className={cn(
                                dataTableCellClass(),
                                'whitespace-nowrap',
                            )}
                        >
                            {row.expected_joining_date ?? (
                                <span className="text-muted-foreground">—</span>
                            )}
                        </TableCell>

                        {/* Actual joining */}
                        <TableCell
                            className={cn(
                                dataTableCellClass(),
                                'whitespace-nowrap',
                            )}
                        >
                            {row.actual_joining_date ?? (
                                <span className="text-muted-foreground">—</span>
                            )}
                        </TableCell>

                        {/* Conversion */}
                        <TableCell className={dataTableCellClass()}>
                            <div className="flex flex-col gap-1">
                                <ConversionBadge
                                    status={row.conversion_status}
                                    label={row.conversion_status_label}
                                />
                                {row.employee?.can_view &&
                                row.employee.id != null &&
                                canViewEmployees ? (
                                    <EmployeeProfileLink
                                        employeeId={row.employee.id}
                                        className="text-xs"
                                    >
                                        {row.employee.employee_no ??
                                            row.employee.name}
                                    </EmployeeProfileLink>
                                ) : null}
                            </div>
                        </TableCell>

                        {/* Time to hire */}
                        <TableCell
                            className={cn(
                                dataTableCellClass(),
                                'whitespace-nowrap',
                            )}
                        >
                            <DurationCell days={row.time_to_hire_days} />
                        </TableCell>

                        {/* Applied at */}
                        <TableCell
                            className={cn(
                                dataTableCellClass(),
                                'whitespace-nowrap text-muted-foreground',
                            )}
                        >
                            {row.created_at ?? '—'}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </OrganizationDataTable>
    );
}
