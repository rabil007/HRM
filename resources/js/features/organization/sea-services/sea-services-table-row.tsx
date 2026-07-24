import { Link, router } from '@inertiajs/react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { TableCell, TableRow } from '@/components/ui/table';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { SeaServiceListRowActions } from '@/features/organization/sea-services/sea-service-list-row-actions';
import type { SeaServiceListItem } from '@/features/organization/sea-services/types';
import { RecordSelectionCell } from '@/features/organization/shared/record-selection-checkbox';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function SeaServicesTableRow({
    seaService,
    viewHref,
    browseHref,
    canUpdate = false,
    canDelete = false,
    selected = false,
    onToggleSelection,
    onEdit,
    onDelete,
}: {
    seaService: SeaServiceListItem;
    viewHref: string;
    browseHref: string;
    canUpdate?: boolean;
    canDelete?: boolean;
    selected?: boolean;
    onToggleSelection?: () => void;
    onEdit?: (seaService: SeaServiceListItem) => void;
    onDelete?: (seaService: SeaServiceListItem) => void;
}) {
    return (
        <TableRow
            className={cn(dataTableBodyRowClass(false), 'cursor-pointer')}
            onClick={() => router.visit(viewHref)}
        >
            <RecordSelectionCell
                checked={selected}
                onToggle={() => onToggleSelection?.()}
                label={`Select sea service for ${seaService.employee_name}`}
            />
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
                            name={seaService.employee_name}
                            image={seaService.employee_image}
                            size="sm"
                        />
                    </Link>
                    <div className="min-w-0">
                        <Link
                            href={browseHref}
                            className="block truncate text-sm font-semibold text-foreground hover:text-primary hover:underline"
                            onClick={(event) => event.stopPropagation()}
                        >
                            {seaService.employee_name}
                        </Link>
                        <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                            {seaService.employee_no}
                        </p>
                        {seaService.department_name ||
                        seaService.position_title ? (
                            <p className="truncate text-[11px] text-muted-foreground/60">
                                {[
                                    seaService.department_name,
                                    seaService.position_title,
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
                    {seaService.vessel_name || '—'}
                </span>
                {seaService.vessel_type_name ? (
                    <p className="truncate text-[11px] text-muted-foreground/60">
                        {seaService.vessel_type_name}
                    </p>
                ) : null}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {seaService.rank_name || '—'}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {seaService.client_name || '—'}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatDisplayDate(seaService.start_date)}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatDisplayDate(seaService.end_date)}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span className="text-sm text-muted-foreground tabular-nums">
                    {seaService.total_months}m {seaService.total_days}d
                </span>
            </TableCell>
            <TableCell
                className={cn(dataTableActionsCellClass(), 'min-w-[10rem]')}
            >
                <SeaServiceListRowActions
                    viewHref={viewHref}
                    showEdit={canUpdate}
                    onEdit={onEdit ? () => onEdit(seaService) : undefined}
                    showDelete={canDelete}
                    onDelete={onDelete ? () => onDelete(seaService) : undefined}
                />
            </TableCell>
        </TableRow>
    );
}
