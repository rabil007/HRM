import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    ChevronsUpDown,
    Clock3,
    ExternalLink,
    History,
    MoreHorizontal,
    Route,
    Ship,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import {
    DataTableHead,
    OrganizationDataTable,
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
import { formatDisplayDateTime12hInTimezone } from '@/lib/company-timezone';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import { show as showEmployee } from '@/routes/organization/employees';
import type {
    AccommodationStaySummary,
    CrewMovementHistoryFilters,
    CrewMovementHistoryRow,
    LinkedAssignmentSummary,
    PayrollDaySummary,
    PhasePeriod,
    PhaseSummary,
    PhaseTimelineEntry,
    TrainingHistoryEntry,
} from './types';

const COLUMN_COUNT = 8;

const columns = {
    assignment: 'w-[270px] min-w-[270px]',
    vessel: 'w-[210px] min-w-[210px]',
    status: 'w-[180px] min-w-[180px]',
    planned: 'w-[235px] min-w-[235px]',
    actual: 'w-[235px] min-w-[235px]',
    duration: 'w-[270px] min-w-[270px]',
    attention: 'w-[170px] min-w-[170px]',
    actions: 'w-[68px] min-w-[68px]',
} as const;

type PhaseRecord = {
    code: string;
    label: string;
    summary: PhaseSummary;
    details?: string[];
};

function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function statusVariant(status: string) {
    if (status === 'active') {
        return 'success' as const;
    }

    if (status === 'cancelled') {
        return 'destructive' as const;
    }

    if (status === 'draft') {
        return 'secondary' as const;
    }

    return 'outline' as const;
}

function phaseStatusVariant(status: string) {
    if (status === 'active') {
        return 'success' as const;
    }

    if (status === 'completed') {
        return 'secondary' as const;
    }

    return 'outline' as const;
}

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
                className="inline-flex max-w-full items-center gap-1.5 transition-colors hover:text-primary"
            >
                <span className="truncate">{label}</span>
                <Icon className="size-3.5 shrink-0" aria-hidden />
            </button>
        </DataTableHead>
    );
}

function Cell({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <TableCell
            className={cn(
                dataTableCellClass(),
                'align-top whitespace-normal',
                className,
            )}
        >
            {children}
        </TableCell>
    );
}

function DatePair({
    label,
    value,
    ongoing = false,
    hint,
}: {
    label: string;
    value: string | null;
    ongoing?: boolean;
    hint?: string | null;
}) {
    return (
        <div className="grid grid-cols-[72px_1fr] gap-2 text-xs leading-5">
            <span className="text-muted-foreground">{label}</span>
            <span className="min-w-0">
                <span className="font-medium text-foreground tabular-nums">
                    {ongoing ? 'Ongoing' : formatDisplayDate(value)}
                </span>
                {hint && value ? (
                    <span className="mt-0.5 block text-[10px] text-muted-foreground">
                        {hint}
                    </span>
                ) : null}
            </span>
        </div>
    );
}

function numericDaysLabel(days: number | null): string {
    if (days === null) {
        return '—';
    }

    return `${days} ${days === 1 ? 'day' : 'days'}`;
}

function PayrollDuration({
    label,
    summary,
}: {
    label: string;
    summary: PayrollDaySummary;
}) {
    const firstPeriod = summary.periods[0];
    const lastPeriod = summary.periods.at(-1);
    const dateRange =
        firstPeriod && lastPeriod
            ? `${formatDisplayDate(firstPeriod.from)} → ${formatDisplayDate(lastPeriod.to)}`
            : '—';
    const periodsLabel =
        summary.periods.length > 1
            ? ` · ${summary.periods.length} periods`
            : '';
    const fullPeriods = summary.periods
        .map(
            (period) =>
                `${formatDisplayDate(period.from)} → ${formatDisplayDate(period.to)} (${numericDaysLabel(period.days)})`,
        )
        .join('; ');

    return (
        <div className="border-t border-border/50 pt-1.5">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 flex items-baseline justify-between gap-2">
                <span
                    className="font-mono text-[10px] tabular-nums"
                    title={fullPeriods || undefined}
                >
                    {dateRange}
                    {periodsLabel}
                </span>
                <span className="shrink-0 font-semibold tabular-nums">
                    {numericDaysLabel(summary.total_days)}
                </span>
            </dd>
        </div>
    );
}

function DetailField({
    label,
    value,
    mono = false,
    hint,
}: {
    label: string;
    value: ReactNode;
    mono?: boolean;
    hint?: string | null;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                {label}
            </dt>
            <dd
                className={cn(
                    'mt-1 text-sm font-medium break-words text-foreground',
                    mono && 'font-mono text-xs',
                )}
            >
                {value ?? '—'}
            </dd>
            {hint ? (
                <p className="mt-0.5 text-[10px] text-muted-foreground">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}

function formatCompanyDateTime(
    value: string | null | undefined,
    timezone: string,
): string {
    if (!value) {
        return '—';
    }

    return formatDisplayDateTime12hInTimezone(value, timezone);
}

function PeriodLine({
    period,
    timezone,
}: {
    period: PhasePeriod;
    timezone: string;
}) {
    const ongoing = period.status === 'active';

    return (
        <div className="grid gap-2 rounded-lg border border-border/60 bg-background/70 p-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
            <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                <span className="font-mono text-[10px] text-muted-foreground">
                    #{period.sequence}
                </span>
                <span className="text-sm font-medium tabular-nums">
                    {formatCompanyDateTime(
                        period.start_at ?? period.start,
                        timezone,
                    )}
                    <span className="px-1.5 text-muted-foreground">→</span>
                    {ongoing
                        ? 'Ongoing'
                        : formatCompanyDateTime(
                              period.end_at ?? period.end,
                              timezone,
                          )}
                </span>
                <Badge variant={phaseStatusVariant(period.status)}>
                    {humanize(period.status)}
                </Badge>
            </div>
            <span className="text-xs font-semibold text-muted-foreground tabular-nums">
                {numericDaysLabel(period.days)}
            </span>
        </div>
    );
}

function PhaseDetail({
    phase,
    timezone,
}: {
    phase: PhaseRecord;
    timezone: string;
}) {
    return (
        <section
            className="grid gap-3 rounded-xl border border-border/70 bg-muted/15 p-3.5 lg:grid-cols-[180px_minmax(0,1fr)]"
            aria-label={`${phase.code} ${phase.label}`}
        >
            <div>
                <div className="flex items-center gap-2">
                    <Badge variant="outline">{phase.code}</Badge>
                    <h4 className="text-sm font-semibold">{phase.label}</h4>
                </div>
                <p className="mt-2 text-xs text-muted-foreground">
                    Elapsed time
                </p>
                <p className="mt-0.5 text-sm font-semibold tabular-nums">
                    {numericDaysLabel(phase.summary.total_days)}
                </p>
            </div>
            <div className="space-y-2">
                {phase.summary.periods.length ? (
                    phase.summary.periods.map((period) => (
                        <PeriodLine
                            key={period.sequence}
                            period={period}
                            timezone={timezone}
                        />
                    ))
                ) : (
                    <div className="rounded-lg border border-dashed border-border/70 px-3 py-2 text-xs text-muted-foreground">
                        No movement recorded for this phase.
                    </div>
                )}
                {phase.details?.length ? (
                    <div className="rounded-lg border border-info/25 bg-info/5 px-3 py-2">
                        <p className="text-[10px] font-semibold tracking-wider text-info uppercase">
                            Training provider / course
                        </p>
                        <ul className="mt-1 space-y-1 text-xs">
                            {phase.details.map((detail, index) => (
                                <li key={`${detail}-${index}`}>{detail}</li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </div>
        </section>
    );
}

function TimelineEntry({
    entry,
    timezone,
}: {
    entry: PhaseTimelineEntry;
    timezone: string;
}) {
    const ongoing = entry.status === 'active';

    return (
        <div className="rounded-lg border border-border/60 bg-background/70 p-3">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant={entry.is_legacy ? 'warning' : 'outline'}>
                    {entry.phase_code.toUpperCase()}
                </Badge>
                <span className="text-sm font-semibold">
                    {entry.phase_label} #{entry.occurrence}
                </span>
                <Badge variant={phaseStatusVariant(entry.status)}>
                    {humanize(entry.status)}
                </Badge>
                <span className="font-mono text-[10px] text-muted-foreground">
                    seq {entry.sequence}
                </span>
            </div>
            <p className="mt-2 text-sm tabular-nums">
                {formatCompanyDateTime(entry.actual_start_at, timezone)}
                <span className="px-1.5 text-muted-foreground">→</span>
                {ongoing
                    ? 'Ongoing'
                    : formatCompanyDateTime(entry.actual_end_at, timezone)}
            </p>
            {(entry.planned_start_at || entry.planned_end_at) && (
                <p className="mt-1 text-xs text-muted-foreground">
                    Planned:{' '}
                    {formatCompanyDateTime(entry.planned_start_at, timezone)} →{' '}
                    {formatCompanyDateTime(entry.planned_end_at, timezone)}
                </p>
            )}
            <p className="mt-1 text-xs font-semibold text-muted-foreground">
                {numericDaysLabel(entry.days)}
            </p>
            {entry.remarks ? (
                <p className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                    {entry.remarks}
                </p>
            ) : null}
        </div>
    );
}

function TrainingCard({
    entry,
    timezone,
}: {
    entry: TrainingHistoryEntry;
    timezone: string;
}) {
    return (
        <div className="rounded-xl border border-border/70 bg-background/75 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h4 className="text-sm font-semibold">
                    Training #{entry.occurrence}
                </h4>
                <Badge variant={phaseStatusVariant(entry.status)}>
                    {humanize(entry.status)}
                </Badge>
            </div>
            <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                <DetailField label="Provider" value={entry.provider ?? '—'} />
                <DetailField label="Course" value={entry.course ?? '—'} />
                <DetailField
                    label="Planned"
                    value={`${formatCompanyDateTime(entry.planned_start_at, timezone)} → ${formatCompanyDateTime(entry.planned_end_at, timezone)}`}
                />
                <DetailField
                    label="Actual"
                    value={`${formatCompanyDateTime(entry.actual_start_at, timezone)} → ${entry.status === 'active' ? 'Ongoing' : formatCompanyDateTime(entry.actual_end_at, timezone)}`}
                />
                <DetailField
                    label="Employee training"
                    value={
                        entry.employee_training_linked
                            ? `Linked${entry.employee_training?.course_name ? ` · ${entry.employee_training.course_name}` : ''}`
                            : 'Not linked'
                    }
                />
                <DetailField label="Remarks" value={entry.remarks ?? '—'} />
            </dl>
        </div>
    );
}

function AccommodationCard({ stay }: { stay: AccommodationStaySummary }) {
    return (
        <div className="rounded-xl border border-border/70 bg-background/75 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h4 className="text-sm font-semibold tracking-wide uppercase">
                    {stay.stay_type_label} accommodation
                </h4>
                <Badge variant="outline">
                    {stay.accommodation_status_label}
                </Badge>
                {stay.is_open ? <Badge variant="warning">Open</Badge> : null}
            </div>
            <dl className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {stay.accommodation_status === 'hotel' ? (
                    <>
                        <DetailField
                            label="Hotel"
                            value={stay.hotel_name ?? '—'}
                        />
                        <DetailField
                            label="Room type"
                            value={stay.room_type_name ?? '—'}
                        />
                        <DetailField
                            label="Check-in"
                            value={formatDisplayDate(stay.check_in_date)}
                        />
                        <DetailField
                            label="Check-out"
                            value={
                                stay.check_out_date
                                    ? formatDisplayDate(stay.check_out_date)
                                    : 'Open'
                            }
                        />
                        <DetailField
                            label="Stay days"
                            value={numericDaysLabel(stay.stay_days)}
                        />
                    </>
                ) : (
                    <DetailField
                        label="Record"
                        value="No Accommodation · Recorded explicitly"
                    />
                )}
                {stay.started_from_phase_label ? (
                    <DetailField
                        label="Starting checkpoint"
                        value={stay.started_from_phase_label}
                    />
                ) : null}
            </dl>
        </div>
    );
}

function LinkedAssignmentCard({
    linked,
    timezone,
}: {
    linked: LinkedAssignmentSummary;
    timezone: string;
}) {
    return (
        <div className="rounded-xl border border-border/70 bg-background/75 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-mono text-sm font-semibold">
                        {linked.assignment_no}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {linked.source_label} · {linked.status_label}
                    </p>
                </div>
                <Button variant="outline" size="sm" asChild>
                    <Link href={showAssignment.url(linked.id)}>
                        Open
                        <ExternalLink className="ml-2 size-3.5" />
                    </Link>
                </Button>
            </div>
            <dl className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <DetailField
                    label="Vessel"
                    value={linked.vessel?.name ?? '—'}
                />
                <DetailField label="Rank" value={linked.rank?.name ?? '—'} />
                <DetailField
                    label="Client"
                    value={linked.client?.name ?? '—'}
                />
                <DetailField
                    label="Started"
                    value={formatCompanyDateTime(linked.started_at, timezone)}
                />
                <DetailField
                    label="Closed"
                    value={formatCompanyDateTime(linked.closed_at, timezone)}
                />
            </dl>
        </div>
    );
}

function FullAssignmentRecord({ row }: { row: CrewMovementHistoryRow }) {
    const timezone = row.company_timezone;
    const tour = row.tour;
    const stays = row.accommodation_stays ?? [];
    const linked = row.linked_assignments;
    const trainingHistory = row.training.history ?? [];
    const timeline = row.phase_timeline ?? [];

    const normalPhases: PhaseRecord[] = [
        {
            code: 'P0',
            label: 'Pre-Mobilisation',
            summary: row.pre_mobilisation,
        },
        { code: 'P2A', label: 'Join Standby', summary: row.join_standby },
        {
            code: 'P2B',
            label: 'Training',
            summary: row.training,
            details: row.training.details,
        },
        { code: 'P4', label: 'On Vessel', summary: row.on_vessel },
        { code: 'P5', label: 'Demob Standby', summary: row.demob_standby },
        {
            code: 'P6',
            label: 'Home / Redeployment',
            summary: row.home_redeploy,
        },
    ];

    const legacyPhases: PhaseRecord[] = [
        {
            code: 'P1',
            label: 'Travel In',
            summary: row.travel_in,
        },
        {
            code: 'P3',
            label: 'Ready to Join',
            summary: row.ready_to_join,
        },
    ].filter((phase) => phase.summary.periods.length > 0);

    return (
        <div
            id={`assignment-details-${row.id}`}
            className="space-y-5 border-t border-primary/15 bg-muted/20 px-4 py-5 sm:px-6"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                        Full assignment record
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        All reference data and recorded phase periods for{' '}
                        {row.assignment_no}.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={showAssignment.url(row.id)}>
                            View assignment
                            <ExternalLink className="ml-2 size-3.5" />
                        </Link>
                    </Button>
                    {row.employee.id ? (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={showEmployee.url(row.employee.id)}>
                                View employee
                                <ExternalLink className="ml-2 size-3.5" />
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </div>

            <section className="rounded-xl border border-border/70 bg-background/75 p-4">
                <h3 className="flex items-center gap-2 text-sm font-semibold">
                    <Ship className="size-4 text-primary" />
                    Assignment & crew
                </h3>
                <dl className="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                    <DetailField
                        label="Assignment number"
                        value={row.assignment_no}
                        mono
                    />
                    <DetailField label="Record ID" value={row.id} mono />
                    <DetailField
                        label="Employee"
                        value={row.employee.name ?? '—'}
                    />
                    <DetailField
                        label="Employee number"
                        value={row.employee.employee_no ?? '—'}
                        mono
                    />
                    <DetailField label="Rank" value={row.rank?.name ?? '—'} />
                    <DetailField
                        label="Vessel"
                        value={row.vessel?.name ?? '—'}
                    />
                    <DetailField
                        label="Client"
                        value={row.client?.name ?? '—'}
                    />
                    <DetailField
                        label="Status"
                        value={
                            <Badge variant={statusVariant(row.status)}>
                                {row.status_label}
                            </Badge>
                        }
                    />
                    <DetailField
                        label="Current phase"
                        value={
                            row.current_phase
                                ? `${row.current_phase.code.toUpperCase()} · ${row.current_phase.label} (${humanize(row.current_phase.status)})`
                                : '—'
                        }
                    />
                    <DetailField
                        label="Created from"
                        value={
                            row.source
                                ? `${row.source_label} (${row.source})`
                                : row.source_label
                        }
                    />
                    <DetailField
                        label="Assignment started"
                        value={formatCompanyDateTime(
                            row.assignment_started_at,
                            timezone,
                        )}
                    />
                    <DetailField
                        label="Assignment closed"
                        value={
                            row.status === 'active'
                                ? 'Ongoing'
                                : formatCompanyDateTime(
                                      row.assignment_closed_at,
                                      timezone,
                                  )
                        }
                    />
                    <DetailField
                        label="Created at"
                        value={formatCompanyDateTime(row.created_at, timezone)}
                    />
                    <DetailField
                        label="Updated at"
                        value={formatCompanyDateTime(row.updated_at, timezone)}
                    />
                    <DetailField
                        label="Company timezone"
                        value={row.company_timezone}
                        mono
                    />
                    <DetailField label="Remarks" value={row.remarks ?? '—'} />
                </dl>
            </section>

            <div className="grid gap-4 xl:grid-cols-2">
                <section className="rounded-xl border border-border/70 bg-background/75 p-4">
                    <h3 className="flex items-center gap-2 text-sm font-semibold">
                        <Route className="size-4 text-primary" />
                        Planned movement
                    </h3>
                    <dl className="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        {row.planned_arrival ? (
                            <DetailField
                                label="Planned arrival"
                                value={formatDisplayDate(row.planned_arrival)}
                                hint={row.planned_arrival_origin_label}
                            />
                        ) : null}
                        <DetailField
                            label="Expected vessel join"
                            value={formatDisplayDate(row.planned_join)}
                            hint={
                                row.planned_join
                                    ? row.planned_join_origin_label
                                    : row.planned_join_origin ===
                                        'movement_actual'
                                      ? 'Transfer/redeployment actual time is not shown as planned join'
                                      : null
                            }
                        />
                        <DetailField
                            label="Planned sign-off"
                            value={formatDisplayDate(row.planned_signoff)}
                            hint={row.planned_signoff_origin_label}
                        />
                        <DetailField
                            label="Planned travel home"
                            value={formatDisplayDate(row.planned_travel_home)}
                            hint={row.planned_travel_home_origin_label}
                        />
                    </dl>
                </section>

                <section className="rounded-xl border border-border/70 bg-background/75 p-4">
                    <h3 className="flex items-center gap-2 text-sm font-semibold">
                        <Clock3 className="size-4 text-primary" />
                        Actual movement
                    </h3>
                    <dl className="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <DetailField
                            label="Actual arrival"
                            value={formatCompanyDateTime(
                                row.actual_arrival_at ?? row.actual_arrival,
                                timezone,
                            )}
                            hint={row.actual_arrival_origin_label}
                        />
                        <DetailField
                            label="Joined vessel"
                            value={formatCompanyDateTime(
                                row.on_vessel.actual_join_at ??
                                    row.on_vessel.actual_join,
                                timezone,
                            )}
                        />
                        <DetailField
                            label="Disembarked"
                            value={
                                row.on_vessel.periods.some(
                                    (period) => period.status === 'active',
                                )
                                    ? 'Ongoing'
                                    : formatCompanyDateTime(
                                          row.on_vessel
                                              .actual_disembarkation_at ??
                                              row.on_vessel
                                                  .actual_disembarkation,
                                          timezone,
                                      )
                            }
                        />
                        <DetailField
                            label="Returned home / P6 start"
                            value={formatCompanyDateTime(
                                row.home_redeploy.actual_return_home_at ??
                                    row.home_redeploy.from,
                                timezone,
                            )}
                        />
                        <DetailField
                            label="On-vessel elapsed days"
                            value={numericDaysLabel(row.on_vessel.total_days)}
                        />
                        <DetailField
                            label="Total assignment elapsed days"
                            value={numericDaysLabel(row.total_assignment_days)}
                        />
                    </dl>
                </section>
            </div>

            {tour ? (
                <section className="rounded-xl border border-border/70 bg-background/75 p-4">
                    <h3 className="text-sm font-semibold">
                        Tour of Duty & sign-off
                    </h3>
                    <dl className="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                        <DetailField
                            label="Tour of Duty days"
                            value={tour.tour_of_duty_days ?? '—'}
                        />
                        <DetailField
                            label="Planned sign-off"
                            value={formatDisplayDate(row.planned_signoff)}
                        />
                        <DetailField
                            label="Sign-off source"
                            value={
                                tour.planned_signoff_source_label ??
                                row.planned_signoff_origin_label ??
                                '—'
                            }
                        />
                        <DetailField
                            label="Override reason"
                            value={tour.planned_signoff_override_reason ?? '—'}
                        />
                        <DetailField
                            label="Days onboard"
                            value={tour.days_onboard ?? '—'}
                        />
                        <DetailField
                            label="Current duty day"
                            value={tour.current_duty_day ?? '—'}
                        />
                        <DetailField
                            label="Remaining tour days"
                            value={tour.remaining_tour_days ?? '—'}
                        />
                        <DetailField
                            label="Tour status"
                            value={tour.tour_status_label ?? '—'}
                        />
                    </dl>
                </section>
            ) : null}

            <section>
                <div className="mb-3">
                    <h3 className="flex items-center gap-2 text-sm font-semibold">
                        <History className="size-4 text-primary" />
                        Complete phase timeline
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Every persisted phase occurrence with company-local
                        timestamps. Legacy P1/P3 appear only when recorded.
                    </p>
                </div>
                {timeline.length > 0 ? (
                    <div className="space-y-3">
                        {timeline.map((entry) => (
                            <TimelineEntry
                                key={`${entry.id}-${entry.sequence}`}
                                entry={entry}
                                timezone={timezone}
                            />
                        ))}
                    </div>
                ) : (
                    <div className="space-y-3">
                        {normalPhases.map((phase) => (
                            <PhaseDetail
                                key={phase.code}
                                phase={phase}
                                timezone={timezone}
                            />
                        ))}
                    </div>
                )}
                {legacyPhases.length > 0 && timeline.length === 0 ? (
                    <section className="mt-5 space-y-3">
                        <div>
                            <h4 className="text-sm font-semibold text-amber-800 dark:text-amber-200">
                                Legacy recorded phases
                            </h4>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Historical Travel In or Ready to Join movement
                                recorded before the modern workflow.
                            </p>
                        </div>
                        {legacyPhases.map((phase) => (
                            <PhaseDetail
                                key={phase.code}
                                phase={phase}
                                timezone={timezone}
                            />
                        ))}
                        {row.planned_travel_in ? (
                            <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 p-3.5">
                                <DetailField
                                    label="Legacy planned travel in"
                                    value={formatDisplayDate(
                                        row.planned_travel_in,
                                    )}
                                    hint={row.planned_travel_in_origin_label}
                                />
                            </div>
                        ) : null}
                    </section>
                ) : null}
            </section>

            {trainingHistory.length > 0 ? (
                <section className="space-y-3">
                    <h3 className="text-sm font-semibold">Training history</h3>
                    {trainingHistory.map((entry) => (
                        <TrainingCard
                            key={`${entry.sequence}-${entry.occurrence}`}
                            entry={entry}
                            timezone={timezone}
                        />
                    ))}
                </section>
            ) : null}

            <section className="space-y-3">
                <h3 className="text-sm font-semibold">Accommodation history</h3>
                {stays.length > 0 ? (
                    stays.map((stay) => (
                        <AccommodationCard key={stay.id} stay={stay} />
                    ))
                ) : (
                    <p className="rounded-xl border border-dashed border-border/70 px-4 py-3 text-sm text-muted-foreground">
                        No accommodation records for this assignment.
                    </p>
                )}
            </section>

            <section className="space-y-3">
                <h3 className="text-sm font-semibold">
                    Linked assignment journey
                </h3>
                {linked?.previous || (linked?.next?.length ?? 0) > 0 ? (
                    <div className="space-y-3">
                        {linked?.relationship_label ? (
                            <p className="text-xs text-muted-foreground">
                                Relationship: {linked.relationship_label}
                            </p>
                        ) : null}
                        {linked?.previous ? (
                            <div>
                                <p className="mb-2 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                    Previous assignment
                                </p>
                                <LinkedAssignmentCard
                                    linked={linked.previous}
                                    timezone={timezone}
                                />
                            </div>
                        ) : null}
                        {(linked?.next?.length ?? 0) > 0 ? (
                            <div className="space-y-2">
                                <p className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                    Next assignment
                                    {(linked?.next?.length ?? 0) > 1 ? 's' : ''}
                                </p>
                                {linked?.next.map((next) => (
                                    <LinkedAssignmentCard
                                        key={next.id}
                                        linked={next}
                                        timezone={timezone}
                                    />
                                ))}
                            </div>
                        ) : null}
                    </div>
                ) : (
                    <p className="rounded-xl border border-dashed border-border/70 px-4 py-3 text-sm text-muted-foreground">
                        No linked transfer or redeployment assignments.
                    </p>
                )}
            </section>

            <section className="rounded-xl border border-border/70 bg-background/75 p-4">
                <h3 className="text-sm font-semibold">
                    Payroll calendar-day preview
                </h3>
                <p className="mt-1 text-xs text-muted-foreground">
                    Inclusive movement calendar days for Sign-On Standby, On
                    Vessel, and Sign-Off Standby. Not the final payroll
                    entitlement.
                </p>
                <dl className="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                    <DetailField
                        label="Sign-on standby"
                        value={numericDaysLabel(
                            row.payroll_days.sign_on_standby.total_days,
                        )}
                    />
                    <DetailField
                        label="On vessel"
                        value={numericDaysLabel(
                            row.payroll_days.onsite.total_days,
                        )}
                    />
                    <DetailField
                        label="Sign-off standby"
                        value={numericDaysLabel(
                            row.payroll_days.sign_off_standby.total_days,
                        )}
                    />
                    <DetailField
                        label="Total"
                        value={numericDaysLabel(row.payroll_days.total_days)}
                    />
                </dl>
            </section>

            <div className="grid gap-4 xl:grid-cols-2">
                <section className="rounded-xl border border-border/70 bg-background/75 p-4">
                    <h3 className="text-sm font-semibold">
                        Corrections & audit
                    </h3>
                    <dl className="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <DetailField
                            label="Approved corrections"
                            value={row.correction_count}
                        />
                        <DetailField
                            label="Last approved correction"
                            value={formatDisplayDate(row.last_corrected_at)}
                        />
                        <DetailField
                            label="Pending correction"
                            value={
                                row.has_pending_corrections ? (
                                    <Badge variant="warning">
                                        Pending review
                                    </Badge>
                                ) : (
                                    'No'
                                )
                            }
                        />
                        <DetailField
                            label="Has approved correction"
                            value={row.has_corrections ? 'Yes' : 'No'}
                        />
                    </dl>
                </section>

                <section className="rounded-xl border border-border/70 bg-background/75 p-4">
                    <h3 className="text-sm font-semibold">
                        Remarks & attention
                    </h3>
                    <div className="mt-4 space-y-4">
                        <div>
                            <p className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                Remarks
                            </p>
                            <p className="mt-1 text-sm whitespace-pre-wrap">
                                {row.remarks ?? '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                Attention warnings
                            </p>
                            {row.warnings.length ? (
                                <ul className="mt-2 space-y-2">
                                    {row.warnings.map((warning, index) => (
                                        <li
                                            key={`${warning}-${index}`}
                                            className="flex items-start gap-2 rounded-lg border border-warning/25 bg-warning/5 px-3 py-2 text-sm"
                                        >
                                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
                                            <span>{warning}</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <div className="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                                    <CheckCircle2 className="size-4 text-success" />
                                    No attention warnings
                                </div>
                            )}
                        </div>
                    </div>
                </section>
            </div>
        </div>
    );
}

export function CrewMovementHistoryReportTable({
    rows,
    total,
    filters,
    onSort,
}: {
    rows: CrewMovementHistoryRow[];
    total: number;
    filters: CrewMovementHistoryFilters;
    onSort: (column: string) => void;
}) {
    const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set());
    const allExpanded = rows.every((row) => expandedRows.has(row.id));

    const toggleRow = (id: number): void => {
        setExpandedRows((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const toggleAll = (): void => {
        setExpandedRows(
            allExpanded ? new Set() : new Set(rows.map((row) => row.id)),
        );
    };

    return (
        <OrganizationDataTable
            minWidth="min-w-[1618px]"
            compact
            tableClassName="table-fixed"
            header={
                <>
                    <div>
                        <p className="text-sm font-semibold">
                            {total.toLocaleString()}{' '}
                            {total === 1 ? 'assignment' : 'assignments'} found
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Showing {rows.length} on this page. Open a record to
                            see every report field and phase period.
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Calendar-day preview uses inclusive dates and counts
                            a shared phase handover date once. Final payroll
                            also depends on contract and payroll-period
                            eligibility.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={toggleAll}
                    >
                        {allExpanded ? 'Collapse all' : 'Expand all records'}
                    </Button>
                </>
            }
        >
            <TableHeader>
                <TableRow>
                    <SortHead
                        column="assignment_no"
                        label="Assignment & crew"
                        filters={filters}
                        onSort={onSort}
                        className={columns.assignment}
                    />
                    <SortHead
                        column="vessel"
                        label="Vessel & rank"
                        filters={filters}
                        onSort={onSort}
                        className={columns.vessel}
                    />
                    <SortHead
                        label="Status & phase"
                        filters={filters}
                        onSort={onSort}
                        className={columns.status}
                    />
                    <SortHead
                        column="planned_join"
                        label="Planned movement"
                        filters={filters}
                        onSort={onSort}
                        className={columns.planned}
                    />
                    <SortHead
                        label="Actual vessel period"
                        filters={filters}
                        onSort={onSort}
                        className={columns.actual}
                    />
                    <SortHead
                        label="Payroll calendar-day preview"
                        filters={filters}
                        onSort={onSort}
                        className={columns.duration}
                    />
                    <SortHead
                        label="Attention & changes"
                        filters={filters}
                        onSort={onSort}
                        className={columns.attention}
                    />
                    <DataTableHead className={columns.actions}>
                        <span className="sr-only">Actions</span>
                    </DataTableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => {
                    const expanded = expandedRows.has(row.id);
                    const vesselOngoing = row.on_vessel.periods.some(
                        (period) => period.status === 'active',
                    );

                    return (
                        <Fragment key={row.id}>
                            <TableRow
                                className={cn(
                                    dataTableBodyRowClass(),
                                    expanded && 'bg-primary/[0.035]',
                                )}
                                aria-expanded={expanded}
                            >
                                <Cell className={columns.assignment}>
                                    <div className="flex items-start gap-2">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="-ml-1 size-8 shrink-0"
                                            onClick={() => toggleRow(row.id)}
                                            aria-expanded={expanded}
                                            aria-controls={`assignment-details-${row.id}`}
                                            aria-label={`${expanded ? 'Collapse' : 'Open'} full record for ${row.assignment_no}`}
                                        >
                                            {expanded ? (
                                                <ChevronDown className="size-4" />
                                            ) : (
                                                <ChevronRight className="size-4" />
                                            )}
                                        </Button>
                                        <div className="min-w-0">
                                            <Link
                                                href={showAssignment.url(
                                                    row.id,
                                                )}
                                                className="font-mono text-xs font-semibold text-primary hover:underline"
                                            >
                                                {row.assignment_no}
                                            </Link>
                                            <p className="mt-1 truncate font-semibold text-foreground">
                                                {row.employee.name ?? '—'}
                                            </p>
                                            <p className="mt-0.5 font-mono text-[11px] text-muted-foreground">
                                                {row.employee.employee_no ??
                                                    'No employee number'}
                                            </p>
                                        </div>
                                    </div>
                                </Cell>
                                <Cell className={columns.vessel}>
                                    <p className="truncate font-semibold">
                                        {row.vessel?.name ?? 'No vessel'}
                                    </p>
                                    <p className="mt-1 truncate text-xs text-muted-foreground">
                                        {row.rank?.name ?? 'No rank'}
                                    </p>
                                    <p className="mt-1 truncate text-[11px] text-muted-foreground">
                                        {row.client?.name ?? 'No client'}
                                    </p>
                                </Cell>
                                <Cell className={columns.status}>
                                    <div className="flex flex-wrap gap-1.5">
                                        <Badge
                                            variant={statusVariant(row.status)}
                                        >
                                            {row.status_label}
                                        </Badge>
                                        {row.current_phase ? (
                                            <Badge variant="outline">
                                                {row.current_phase.code.toUpperCase()}
                                            </Badge>
                                        ) : null}
                                    </div>
                                    <p className="mt-2 truncate text-xs text-muted-foreground">
                                        {row.current_phase?.label ??
                                            'No current phase'}
                                    </p>
                                </Cell>
                                <Cell className={columns.planned}>
                                    {row.planned_arrival ? (
                                        <DatePair
                                            label="Arrival"
                                            value={row.planned_arrival}
                                            hint={
                                                row.planned_arrival_origin_label
                                            }
                                        />
                                    ) : null}
                                    <DatePair
                                        label="Join"
                                        value={row.planned_join}
                                        hint={row.planned_join_origin_label}
                                    />
                                    <DatePair
                                        label="Sign-off"
                                        value={row.planned_signoff}
                                        hint={row.planned_signoff_origin_label}
                                    />
                                    <DatePair
                                        label="Home"
                                        value={row.planned_travel_home}
                                        hint={
                                            row.planned_travel_home_origin_label
                                        }
                                    />
                                </Cell>
                                <Cell className={columns.actual}>
                                    {row.actual_arrival ? (
                                        <DatePair
                                            label="Arrived"
                                            value={row.actual_arrival}
                                            hint={
                                                row.actual_arrival_origin_label
                                            }
                                        />
                                    ) : null}
                                    <DatePair
                                        label="Joined"
                                        value={row.on_vessel.actual_join}
                                    />
                                    <DatePair
                                        label="Left"
                                        value={
                                            row.on_vessel.actual_disembarkation
                                        }
                                        ongoing={vesselOngoing}
                                    />
                                </Cell>
                                <Cell className={columns.duration}>
                                    <dl className="space-y-1.5 text-xs">
                                        <div className="flex items-baseline justify-between gap-2">
                                            <dt className="font-medium text-foreground">
                                                Calendar-day total
                                            </dt>
                                            <dd className="font-bold tabular-nums">
                                                {numericDaysLabel(
                                                    row.payroll_days.total_days,
                                                )}
                                            </dd>
                                        </div>
                                        <PayrollDuration
                                            label="Sign-on standby"
                                            summary={
                                                row.payroll_days.sign_on_standby
                                            }
                                        />
                                        <PayrollDuration
                                            label="On vessel"
                                            summary={row.payroll_days.onsite}
                                        />
                                        <PayrollDuration
                                            label="Sign-off standby"
                                            summary={
                                                row.payroll_days
                                                    .sign_off_standby
                                            }
                                        />
                                    </dl>
                                </Cell>
                                <Cell className={columns.attention}>
                                    <div className="flex flex-wrap gap-1.5">
                                        {row.needs_attention ? (
                                            <Badge variant="warning">
                                                <AlertTriangle />
                                                {row.warnings.length}{' '}
                                                {row.warnings.length === 1
                                                    ? 'warning'
                                                    : 'warnings'}
                                            </Badge>
                                        ) : (
                                            <Badge variant="success">
                                                <CheckCircle2 />
                                                Clear
                                            </Badge>
                                        )}
                                        {row.has_pending_corrections ? (
                                            <Badge variant="warning">
                                                Pending correction
                                            </Badge>
                                        ) : row.has_corrections ? (
                                            <Badge variant="secondary">
                                                {row.correction_count}{' '}
                                                {row.correction_count === 1
                                                    ? 'correction'
                                                    : 'corrections'}
                                            </Badge>
                                        ) : null}
                                    </div>
                                    <p className="mt-2 truncate text-[11px] text-muted-foreground">
                                        Source: {row.source_label}
                                    </p>
                                </Cell>
                                <Cell className={columns.actions}>
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
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    toggleRow(row.id)
                                                }
                                            >
                                                {expanded
                                                    ? 'Collapse record'
                                                    : 'Open full record'}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem asChild>
                                                <Link
                                                    href={showAssignment.url(
                                                        row.id,
                                                    )}
                                                >
                                                    View assignment
                                                </Link>
                                            </DropdownMenuItem>
                                            {row.employee.id ? (
                                                <DropdownMenuItem asChild>
                                                    <Link
                                                        href={showEmployee.url(
                                                            row.employee.id,
                                                        )}
                                                    >
                                                        View employee
                                                    </Link>
                                                </DropdownMenuItem>
                                            ) : null}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </Cell>
                            </TableRow>
                            {expanded ? (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={COLUMN_COUNT}
                                        className="p-0 whitespace-normal"
                                    >
                                        <FullAssignmentRecord row={row} />
                                    </TableCell>
                                </TableRow>
                            ) : null}
                        </Fragment>
                    );
                })}
            </TableBody>
        </OrganizationDataTable>
    );
}
