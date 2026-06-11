import type { ReactElement } from 'react';
import { EmployeeProfileLink } from '@/features/organization/crew-deployments/employee-profile-link';

export function DeploymentDetailField({
    label,
    value,
    employeeId,
    subdued = false,
}: {
    label: string;
    value: string;
    employeeId?: number;
    subdued?: boolean;
}): ReactElement {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-3.5">
            <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                {label}
            </div>
            <div
                className={
                    subdued
                        ? 'text-right text-sm text-muted-foreground'
                        : 'text-right text-sm font-medium'
                }
            >
                {employeeId && value !== '—' ? (
                    <EmployeeProfileLink employeeId={employeeId} className="text-sm">
                        {value}
                    </EmployeeProfileLink>
                ) : (
                    value
                )}
            </div>
        </div>
    );
}
