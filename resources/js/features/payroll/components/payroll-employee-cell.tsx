import { Link } from '@inertiajs/react';
import { show as showEmployee } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import { dataTableCellPrimaryClass } from '@/components/data-table';
import { TableCell } from '@/components/ui/table';
import { resolveEmployeeImageUrl } from '@/features/organization/employees/lib/employee-avatar';
import { cn } from '@/lib/utils';
import type { PayrollEmployeeIdentity } from '../types';

export function formatPayrollEmployeeAssignment(
    employee: PayrollEmployeeIdentity,
): string | null {
    const departmentName = employee.department?.name;
    const parentDepartmentName = employee.department?.parent?.name;
    const positionTitle = employee.position?.title;

    let departmentLabel: string | null = null;

    if (departmentName) {
        departmentLabel = parentDepartmentName
            ? `${parentDepartmentName} / ${departmentName}`
            : departmentName;
    }

    if (departmentLabel && positionTitle) {
        return `${departmentLabel} · ${positionTitle}`;
    }

    return departmentLabel ?? positionTitle ?? null;
}

type PayrollEmployeeCellProps = {
    employee: PayrollEmployeeIdentity;
    isExcluded?: boolean;
    className?: string;
    asTableCell?: boolean;
};

export function PayrollEmployeeCell({
    employee,
    isExcluded = false,
    className,
    asTableCell = true,
}: PayrollEmployeeCellProps) {
    const assignment = formatPayrollEmployeeAssignment(employee);

    const content = (
        <Link
            href={showEmployee.url(employee.id)}
            className="group/link flex items-center gap-3"
        >
            <div
                className={cn(
                    'relative flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-xl border text-xs font-bold shadow-sm transition-all duration-200 group-hover/link:scale-105',
                    isExcluded
                        ? 'border-border/40 bg-muted/50 text-muted-foreground'
                        : 'border-primary/20 bg-gradient-to-br from-primary/10 to-primary/25 text-primary dark:border-primary/15',
                )}
            >
                {employee.image ? (
                    <img
                        src={
                            resolveEmployeeImageUrl(employee.image) ?? undefined
                        }
                        alt=""
                        className="h-full w-full object-cover"
                    />
                ) : (
                    employee.name
                        .split(' ')
                        .filter(Boolean)
                        .slice(0, 2)
                        .map((part) => part[0]?.toUpperCase())
                        .join('') || '—'
                )}
            </div>
            <div className="min-w-0">
                <span
                    className={cn(
                        'block truncate leading-tight font-semibold transition-colors group-hover/link:text-primary',
                        isExcluded && 'line-through',
                    )}
                >
                    {employee.name}
                </span>
                <span className="mt-0.5 block font-mono text-[11px] text-muted-foreground/70">
                    {employee.employee_no ?? '—'}
                </span>
                {assignment ? (
                    <span className="mt-0.5 block truncate text-[11px] text-muted-foreground/80">
                        {assignment}
                    </span>
                ) : null}
            </div>
        </Link>
    );

    if (!asTableCell) {
        return <div className={className}>{content}</div>;
    }

    return (
        <TableCell className={cn(dataTableCellPrimaryClass(), className)}>
            {content}
        </TableCell>
    );
}
