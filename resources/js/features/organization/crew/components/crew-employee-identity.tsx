import type { ReactElement } from 'react';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';

export function CrewEmployeeIdentity({
    employee,
    rankName,
    showAvatar = true,
    showEmployeeNo = true,
}: {
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
        image?: string | null;
    } | null;
    rankName?: string | null;
    showAvatar?: boolean;
    showEmployeeNo?: boolean;
}): ReactElement {
    if (!employee) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="flex min-w-0 items-center gap-3">
            {showAvatar ? (
                <EmployeeProfileLink
                    employeeId={employee.id}
                    stopRowNavigation
                    className="shrink-0"
                >
                    <EmployeeAvatar
                        name={employee.name}
                        image={employee.image}
                        size="sm"
                    />
                </EmployeeProfileLink>
            ) : null}
            <div className="min-w-0">
                <EmployeeProfileLink
                    employeeId={employee.id}
                    className="block truncate text-sm font-semibold"
                    stopRowNavigation
                >
                    {employee.name}
                </EmployeeProfileLink>
                {showEmployeeNo && employee.employee_no ? (
                    <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                        {employee.employee_no}
                    </p>
                ) : null}
                {rankName ? (
                    <p className="truncate text-[11px] text-muted-foreground/60">
                        {rankName}
                    </p>
                ) : null}
            </div>
        </div>
    );
}
