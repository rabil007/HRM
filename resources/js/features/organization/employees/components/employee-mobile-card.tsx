import { MobileRecordCard } from '@/components/mobile-record-list';
import type { MobileRecordOverflowAction } from '@/components/mobile-record-list';
import { EmployeeStatusBadge } from '@/features/organization/employees/components/employee-status-badge';
import { employeeMobileCardModel } from '@/features/organization/employees/lib/employee-mobile-card';
import type { Employee, EmployeePageCan } from '../types';

export function EmployeeMobileCard({
    employee,
    showUrl,
    can,
    onDelete,
}: {
    employee: Employee;
    showUrl: string;
    can: Pick<EmployeePageCan, 'update' | 'delete'>;
    onDelete?: (employee: Employee) => void;
}) {
    const model = employeeMobileCardModel(employee, can);
    const overflowActions: MobileRecordOverflowAction[] = [];

    if (model.showEdit) {
        overflowActions.push({
            key: 'edit',
            label: 'Edit',
            href: showUrl,
        });
    }

    if (model.showDelete && onDelete) {
        overflowActions.push({
            key: 'delete',
            label: 'Delete',
            destructive: true,
            onSelect: () => onDelete(employee),
        });
    }

    return (
        <MobileRecordCard
            title={model.title}
            subtitle={model.subtitle}
            meta={[model.assignmentLine]}
            status={<EmployeeStatusBadge status={model.status} />}
            attention={model.attention}
            href={showUrl}
            overflowActions={overflowActions}
        />
    );
}
