import { MobileRecordCard } from '@/components/mobile-record-list';
import { formatDisplayDateTime } from '@/lib/format-date';
import type { DeletedEmployee } from '../types';

function formatEmployeeStatus(status: string): string {
    return status
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

export function DeletedEmployeeMobileCard({
    employee,
    canRestore,
    onRestore,
}: {
    employee: DeletedEmployee;
    canRestore: boolean;
    onRestore: (employee: DeletedEmployee) => void;
}) {
    return (
        <MobileRecordCard
            title={employee.name}
            subtitle={employee.employee_no}
            meta={[
                employee.department?.name ?? null,
                employee.position?.title ?? null,
                `Previous status: ${formatEmployeeStatus(employee.status)}`,
                employee.deleted_at
                    ? `Deleted ${formatDisplayDateTime(employee.deleted_at)}`
                    : null,
            ]}
            primaryAction={
                canRestore
                    ? {
                          label: 'Restore',
                          onClick: () => onRestore(employee),
                      }
                    : undefined
            }
        />
    );
}
