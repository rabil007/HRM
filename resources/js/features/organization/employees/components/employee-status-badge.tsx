import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { Employee } from '../types';

const EMPLOYEE_STATUS_LABELS: Record<Employee['status'], string> = {
    active: 'Active',
    inactive: 'Inactive',
    on_leave: 'On leave',
    terminated: 'Terminated',
};

const STATUS_STYLES: Record<Employee['status'], string> = {
    active: 'border-success/30 bg-success/10 text-success',
    inactive: 'border-border bg-muted/50 text-muted-foreground',
    on_leave: 'border-warning/30 bg-warning/10 text-warning',
    terminated: 'border-destructive/30 bg-destructive/10 text-destructive',
};

export function EmployeeStatusBadge({
    status,
}: {
    status: Employee['status'];
}): ReactElement {
    return (
        <Badge
            variant="outline"
            className={cn('font-medium', STATUS_STYLES[status])}
        >
            {EMPLOYEE_STATUS_LABELS[status]}
        </Badge>
    );
}
