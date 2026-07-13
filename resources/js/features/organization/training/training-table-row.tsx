import { Link, router } from '@inertiajs/react';
import {
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { TableCell, TableRow } from '@/components/ui/table';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { TrainingExpiryBadge } from '@/features/organization/training/training-expiry-badge';
import { trainingExpiryRemainingClass } from '@/features/organization/training/training-expiry';
import type { TrainingListItem } from '@/features/organization/training/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function TrainingTableRow({
    training,
    viewHref,
    browseHref,
}: {
    training: TrainingListItem;
    viewHref: string;
    browseHref: string;
}) {
    return (
        <TableRow
            className={cn(dataTableBodyRowClass(false), 'cursor-pointer')}
            onClick={() => router.visit(viewHref)}
        >
            <TableCell
                className={cn(dataTableCellPrimaryClass(), 'min-w-[180px]')}
            >
                <div className="flex min-w-0 items-center gap-3">
                    <Link
                        href={browseHref}
                        className="shrink-0"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <EmployeeAvatar
                            name={training.employee_name}
                            image={training.employee_image}
                            size="sm"
                        />
                    </Link>
                    <div className="min-w-0">
                        <Link
                            href={browseHref}
                            className="block truncate text-sm font-semibold text-foreground hover:text-primary hover:underline"
                            onClick={(event) => event.stopPropagation()}
                        >
                            {training.employee_name}
                        </Link>
                        <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                            {training.employee_no}
                        </p>
                        {training.department_name || training.position_title ? (
                            <p className="truncate text-[11px] text-muted-foreground/60">
                                {[
                                    training.department_name,
                                    training.position_title,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        ) : null}
                    </div>
                </div>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span className="font-medium text-foreground">
                    {training.course_name || '—'}
                </span>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatDisplayDate(training.issue_date)}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <div className="space-y-1">
                    <TrainingExpiryBadge status={training.expiry_status} />
                    <p
                        className={cn(
                            'text-[11px]',
                            trainingExpiryRemainingClass(training.expiry_status),
                        )}
                    >
                        {training.expiry_label}
                    </p>
                </div>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {training.institute_center || '—'}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {training.country_name || '—'}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {training.has_certificate ? (
                    <span className="text-sm text-emerald-500">Yes</span>
                ) : (
                    <span className="text-sm text-muted-foreground">No</span>
                )}
            </TableCell>
        </TableRow>
    );
}
