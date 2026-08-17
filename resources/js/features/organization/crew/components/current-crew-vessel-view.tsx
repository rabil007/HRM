import { router } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import {
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Table,
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { CrewReliefReadinessBadge } from '@/features/organization/crew/components/crew-relief-readiness-badge';
import type { CurrentCrewVesselRow } from '@/features/organization/crew/types';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as showAssignment } from '@/routes/organization/crew-assignments';

export function CurrentCrewVesselView({
    vessels,
}: {
    vessels: CurrentCrewVesselRow[];
}) {
    const [expandedIds, setExpandedIds] = useState<Set<number>>(
        () => new Set(),
    );

    const allExpanded =
        vessels.length > 0 &&
        vessels.every((vessel) => expandedIds.has(vessel.id));

    const toggleVessel = (vesselId: number, open: boolean) => {
        setExpandedIds((current) => {
            const next = new Set(current);

            if (open) {
                next.add(vesselId);
            } else {
                next.delete(vesselId);
            }

            return next;
        });
    };

    const expandAll = () => {
        setExpandedIds(new Set(vessels.map((vessel) => vessel.id)));
    };

    const collapseAll = () => {
        setExpandedIds(new Set());
    };

    return (
        <div className="space-y-3">
            <div className="flex justify-end">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={allExpanded ? collapseAll : expandAll}
                >
                    {allExpanded ? 'Collapse all' : 'Expand all'}
                </Button>
            </div>

            <div className="space-y-2">
                {vessels.map((vessel) => {
                    const open = expandedIds.has(vessel.id);

                    return (
                        <Collapsible
                            key={vessel.id}
                            open={open}
                            onOpenChange={(nextOpen) =>
                                toggleVessel(vessel.id, nextOpen)
                            }
                        >
                            <div className="overflow-hidden rounded-xl glass-card">
                                <CollapsibleTrigger asChild>
                                    <button
                                        type="button"
                                        className="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-accent/40 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                        aria-expanded={open}
                                        aria-label={`${open ? 'Collapse' : 'Expand'} ${vessel.name}`}
                                    >
                                        {open ? (
                                            <ChevronDown
                                                className="mt-1 size-4 shrink-0 text-muted-foreground"
                                                aria-hidden
                                            />
                                        ) : (
                                            <ChevronRight
                                                className="mt-1 size-4 shrink-0 text-muted-foreground"
                                                aria-hidden
                                            />
                                        )}
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-semibold text-foreground">
                                                {vessel.name}
                                            </span>
                                            <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                <span>
                                                    {vessel.onboard_count}{' '}
                                                    onboard
                                                </span>
                                                <span>
                                                    Required{' '}
                                                    {vessel.required_count}
                                                </span>
                                                <span
                                                    className={cn(
                                                        vessel.gap > 0 &&
                                                            'font-medium text-amber-700 dark:text-amber-300',
                                                        vessel.gap === 0 &&
                                                            'font-medium text-emerald-700 dark:text-emerald-300',
                                                        vessel.gap < 0 &&
                                                            'font-medium text-sky-700 dark:text-sky-300',
                                                    )}
                                                >
                                                    {vessel.coverage_label}
                                                </span>
                                                {vessel.client_name ? (
                                                    <span>
                                                        {vessel.client_name}
                                                    </span>
                                                ) : null}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-sm font-medium text-muted-foreground tabular-nums">
                                            {vessel.onboard_count} /{' '}
                                            {vessel.required_count}
                                        </span>
                                    </button>
                                </CollapsibleTrigger>

                                <CollapsibleContent>
                                    <div className="overflow-x-auto border-t border-border/60">
                                        <Table className="min-w-[1080px] table-fixed">
                                            <TableHeader>
                                                <DataTableHeaderRow>
                                                    <DataTableHead className="w-[220px]">
                                                        Employee
                                                    </DataTableHead>
                                                    <DataTableHead className="w-[120px]">
                                                        Employee No
                                                    </DataTableHead>
                                                    <DataTableHead className="w-[140px]">
                                                        Rank
                                                    </DataTableHead>
                                                    <DataTableHead className="w-[120px]">
                                                        P4 Joined
                                                    </DataTableHead>
                                                    <DataTableHead className="w-[110px]">
                                                        Days Onboard
                                                    </DataTableHead>
                                                    <DataTableHead className="w-[140px]">
                                                        Planned Sign-Off
                                                    </DataTableHead>
                                                    <DataTableHead className="w-[180px]">
                                                        Relief Status
                                                    </DataTableHead>
                                                </DataTableHeaderRow>
                                            </TableHeader>
                                            <TableBody>
                                                {vessel.crew.map(
                                                    (assignment) => (
                                                        <TableRow
                                                            key={assignment.id}
                                                            className={cn(
                                                                dataTableBodyRowClass(),
                                                                'cursor-pointer',
                                                            )}
                                                            onClick={() =>
                                                                router.visit(
                                                                    showAssignment.url(
                                                                        assignment.id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            <TableCell
                                                                className={dataTableCellPrimaryClass()}
                                                            >
                                                                {assignment.employee ? (
                                                                    <EmployeeProfileLink
                                                                        employeeId={
                                                                            assignment
                                                                                .employee
                                                                                .id
                                                                        }
                                                                        stopRowNavigation
                                                                    >
                                                                        {
                                                                            assignment
                                                                                .employee
                                                                                .name
                                                                        }
                                                                    </EmployeeProfileLink>
                                                                ) : (
                                                                    '—'
                                                                )}
                                                            </TableCell>
                                                            <TableCell
                                                                className={cn(
                                                                    dataTableCellClass(),
                                                                    'font-mono text-xs',
                                                                )}
                                                            >
                                                                {assignment
                                                                    .employee
                                                                    ?.employee_no ??
                                                                    '—'}
                                                            </TableCell>
                                                            <TableCell
                                                                className={dataTableCellClass()}
                                                            >
                                                                {assignment.rank
                                                                    ?.name ??
                                                                    '—'}
                                                            </TableCell>
                                                            <TableCell
                                                                className={dataTableCellClass()}
                                                            >
                                                                {formatDisplayDate(
                                                                    assignment.actual_join_at ??
                                                                        assignment
                                                                            .movement_context
                                                                            .actual_join_at,
                                                                )}
                                                            </TableCell>
                                                            <TableCell
                                                                className={dataTableCellClass()}
                                                            >
                                                                {assignment.days_onboard ??
                                                                    '—'}
                                                            </TableCell>
                                                            <TableCell
                                                                className={dataTableCellClass()}
                                                            >
                                                                {formatDisplayDate(
                                                                    assignment.planned_signoff_at,
                                                                )}
                                                            </TableCell>
                                                            <TableCell
                                                                className={dataTableCellClass()}
                                                            >
                                                                <CrewReliefReadinessBadge
                                                                    relief_status={
                                                                        assignment.relief_status
                                                                    }
                                                                    relief_status_label={
                                                                        assignment.relief_status_label
                                                                    }
                                                                    relief_risk={
                                                                        assignment.relief_risk
                                                                    }
                                                                    relief_risk_label={
                                                                        assignment.relief_risk_label
                                                                    }
                                                                    relief_employee={
                                                                        assignment.relief_employee
                                                                    }
                                                                />
                                                            </TableCell>
                                                        </TableRow>
                                                    ),
                                                )}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </CollapsibleContent>
                            </div>
                        </Collapsible>
                    );
                })}
            </div>
        </div>
    );
}
