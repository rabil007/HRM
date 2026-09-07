import { Link, router } from '@inertiajs/react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';
import { CrewMobilisationReadinessBadge } from '@/features/organization/crew/components/crew-mobilisation-readiness-badge';
import { CrewReliefReadinessBadge } from '@/features/organization/crew/components/crew-relief-readiness-badge';
import {
    reliefDeskDutySummary,
    reliefDeskSignoffSummary,
} from '@/features/organization/crew-planning/lib/relief-desk-mobile-card';
import type { ReliefDeskRow } from '@/features/organization/crew-planning/types';
import { cn } from '@/lib/utils';

const RISK_STYLES: Record<string, string> = {
    none: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
    warning:
        'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200',
    critical: 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300',
};

export function ReliefDeskTableRow({ row }: { row: ReliefDeskRow }) {
    const visitHref = row.source_href;
    const action = row.recommended_action;

    return (
        <TableRow
            className={cn(
                dataTableBodyRowClass(Boolean(visitHref)),
                visitHref ? 'cursor-pointer' : '',
            )}
            onClick={() => {
                if (visitHref) {
                    router.visit(visitHref);
                }
            }}
        >
            <TableCell
                className={cn(dataTableCellPrimaryClass(), 'min-w-[160px]')}
            >
                <div className="min-w-0">
                    {row.vessel?.href ? (
                        <Link
                            href={row.vessel.href}
                            className="truncate font-semibold text-foreground hover:underline"
                            onClick={(event) => event.stopPropagation()}
                        >
                            {row.vessel.name}
                        </Link>
                    ) : (
                        <p className="truncate font-semibold">
                            {row.vessel?.name ?? '—'}
                        </p>
                    )}
                    <p className="truncate text-[11px] text-muted-foreground">
                        {row.rank?.name ?? '—'}
                    </p>
                </div>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'min-w-[160px]')}>
                {row.employee?.href ? (
                    <Link
                        href={row.employee.href}
                        className="truncate font-medium text-primary hover:underline"
                        onClick={(event) => event.stopPropagation()}
                    >
                        {row.employee.name}
                    </Link>
                ) : (
                    <p className="truncate font-medium">
                        {row.employee?.name ?? 'Unassigned'}
                    </p>
                )}
                <p className="truncate text-[11px] text-muted-foreground">
                    {row.assignment_no} · {reliefDeskDutySummary(row)}
                </p>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'min-w-[140px]')}>
                <p className="font-medium">{reliefDeskSignoffSummary(row)}</p>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'min-w-[180px]')}>
                <CrewReliefReadinessBadge
                    relief_status={row.relief_status}
                    relief_status_label={row.relief_status_label}
                    relief_risk={row.relief_risk}
                    relief_risk_label={row.relief_risk_label}
                    relief_employee={row.relief_employee}
                />
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'min-w-[120px]')}>
                <p className="truncate text-sm">
                    {row.relief_phase_label ?? '—'}
                </p>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'min-w-[120px]')}>
                {row.mobilisation_readiness ? (
                    <CrewMobilisationReadinessBadge
                        readiness={row.mobilisation_readiness}
                        compact
                    />
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'min-w-[100px]')}>
                <Badge
                    variant="outline"
                    className={cn(
                        'w-fit font-medium',
                        RISK_STYLES[row.relief_risk] ?? RISK_STYLES.none,
                    )}
                >
                    {row.relief_risk_label}
                </Badge>
            </TableCell>
            <TableCell className={dataTableActionsCellClass()}>
                {action.href ? (
                    <Button asChild size="sm" variant="outline">
                        <Link
                            href={action.href}
                            onClick={(event) => event.stopPropagation()}
                        >
                            {action.label}
                        </Link>
                    </Button>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        {action.label}
                    </span>
                )}
            </TableCell>
        </TableRow>
    );
}
