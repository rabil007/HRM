import {
    attendanceHref,
    canViewCrewOperations,
    canViewPayroll,
    crewOperationsHref,
    has,
    payrollHref,
} from '@/lib/nav-visibility';
import { dashboard } from '@/routes';
import { employees } from '@/routes/organization';

export type TopNavLink = {
    title: string;
    href: string;
    isActive: boolean;
    disabled?: boolean;
};

export { attendanceHref, crewOperationsHref, payrollHref };

export function getTopNavLinks(
    permissions: string[],
    url: string,
): TopNavLink[] {
    const links: TopNavLink[] = [
        {
            title: 'Overview',
            href: dashboard.url(),
            isActive: url === dashboard.url(),
        },
    ];

    if (has(permissions, 'employees.view')) {
        links.push({
            title: 'Employees',
            href: employees.url(),
            isActive: url.startsWith('/organization/employees'),
        });
    }

    if (canViewCrewOperations(permissions)) {
        const href = crewOperationsHref(permissions);

        links.push({
            title: 'Crew Operations',
            href,
            isActive:
                url.startsWith('/organization/vessels') ||
                url.startsWith('/organization/vessel-manning') ||
                url.startsWith('/organization/crew-planning') ||
                url.startsWith('/organization/crew-operations') ||
                url.startsWith('/organization/crew') ||
                url.startsWith('/organization/crew-movement-corrections'),
        });
    }

    const attendanceLanding = attendanceHref(permissions);

    if (attendanceLanding) {
        links.push({
            title: 'Attendance',
            href: attendanceLanding,
            isActive:
                url.startsWith('/attendance/') &&
                !url.startsWith('/attendance/leave-requests'),
        });
    }

    if (has(permissions, 'attendance.leave-requests.view')) {
        links.push({
            title: 'Leave',
            href: '/attendance/leave-requests',
            isActive: url.startsWith('/attendance/leave-requests'),
        });
    }

    if (canViewPayroll(permissions)) {
        links.push({
            title: 'Payroll',
            href: payrollHref(permissions),
            isActive: url.startsWith('/payroll'),
        });
    }

    return links;
}
