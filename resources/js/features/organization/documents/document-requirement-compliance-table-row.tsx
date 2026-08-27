import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';
import type { RequirementComplianceItem } from '@/features/organization/documents/shared/types';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { cn } from '@/lib/utils';

const STATUS_CLASSES: Record<string, string> = {
    valid: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
    expiring: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
    expired: 'bg-red-500/15 text-red-400 border-red-500/30',
    missing: 'bg-violet-500/10 text-violet-400 border-violet-500/20',
};

const STATUS_LABELS: Record<string, string> = {
    valid: 'Valid',
    expiring: 'Expiring',
    expired: 'Expired',
    missing: 'Missing',
};

export function DocumentRequirementComplianceTableRow({
    item,
    canUpload = false,
    onUpload,
    onView,
    onReplace,
}: {
    item: RequirementComplianceItem;
    canUpload?: boolean;
    onUpload?: (item: RequirementComplianceItem) => void;
    onView?: (item: RequirementComplianceItem) => void;
    onReplace?: (item: RequirementComplianceItem) => void;
}) {
    const canOpenDocument = item.document_id !== null;

    return (
        <TableRow
            className={cn(
                dataTableBodyRowClass(false),
                canOpenDocument && 'cursor-pointer',
            )}
            onClick={(event) => {
                const target = event.target;

                if (
                    !(target instanceof Element) ||
                    target.closest('a, button, [data-row-ignore-click]')
                ) {
                    return;
                }

                if (canOpenDocument) {
                    onView?.(item);
                }
            }}
        >
            <TableCell className={cn(dataTableCellClass(), 'min-w-[140px]')}>
                <div className="min-w-0">
                    <EmployeeProfileLink
                        employeeId={item.employee_id}
                        className="block truncate text-sm font-semibold text-foreground hover:text-primary"
                        stopRowNavigation
                    >
                        {item.employee_name}
                    </EmployeeProfileLink>
                    <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                        {item.employee_no}
                    </p>
                </div>
            </TableCell>
            <TableCell
                className={cn(dataTableCellClass(), 'hidden sm:table-cell')}
            >
                <p className="truncate text-sm text-muted-foreground">
                    {item.department_name || '—'}
                </p>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'min-w-[160px]')}>
                <p className="truncate text-sm font-medium">
                    {item.document_type}
                </p>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <Badge
                    variant="outline"
                    className={cn(
                        'font-normal capitalize',
                        STATUS_CLASSES[item.status],
                    )}
                >
                    {STATUS_LABELS[item.status] ?? item.status}
                </Badge>
            </TableCell>
            <TableCell
                className={dataTableActionsCellClass()}
                data-row-ignore-click
                onClick={(event) => event.stopPropagation()}
            >
                {item.status === 'missing' && canUpload ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => onUpload?.(item)}
                    >
                        Upload
                    </Button>
                ) : null}
                {item.status === 'expired' && item.document_id !== null ? (
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => onView?.(item)}
                        >
                            View
                        </Button>
                        {canUpload ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => onReplace?.(item)}
                            >
                                Replace
                            </Button>
                        ) : null}
                    </div>
                ) : null}
                {item.status === 'expiring' && item.document_id !== null ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => onView?.(item)}
                    >
                        View
                    </Button>
                ) : null}
                {item.status === 'valid' && item.document_id !== null ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => onView?.(item)}
                    >
                        View
                    </Button>
                ) : null}
            </TableCell>
        </TableRow>
    );
}

export function requirementStatusLabel(status: string): string {
    return STATUS_LABELS[status] ?? status;
}

export function requirementStatusClass(status: string): string {
    return STATUS_CLASSES[status] ?? '';
}
