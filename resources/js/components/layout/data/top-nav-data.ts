import {
    canViewCrewOperations,
    canViewPayroll,
    has,
} from '@/components/layout/data/sidebar-data';
import { dashboard } from '@/routes';
import { employees } from '@/routes/organization';

export type TopNavLink = {
    title: string;
    href: string;
    isActive: boolean;
    disabled?: boolean;
};

function crewOperationsHref(permissions: string[]): string {
    if (has(permissions, 'crew_operations.deployments.view')) {
        return '/organization/crew-deployments';
    }

    if (has(permissions, 'crew_operations.overview.view')) {
        return '/organization/crew-operations';
    }

    if (has(permissions, 'crew_operations.planning.view')) {
        return '/organization/crew-planning';
    }

    return '/organization/vessel-manning';
}

function payrollHref(permissions: string[]): string {
    if (
        has(permissions, 'payroll.periods.view') ||
        has(permissions, 'payroll.crew_timesheets.view')
    ) {
        return '/payroll';
    }

    if (has(permissions, 'payroll.records.view')) {
        return '/payroll/records';
    }

    if (
        has(permissions, 'payroll.salary_inputs.view') ||
        has(permissions, 'payroll.periods.update') ||
        has(permissions, 'payroll.salary_inputs.create')
    ) {
        return '/payroll/salary-inputs';
    }

    if (has(permissions, 'payroll.payslips.view')) {
        return '/payroll/payslips';
    }

    return '/payroll/wps';
}

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
                url.startsWith('/organization/crew-deployments') ||
                url.startsWith('/organization/vessel-manning') ||
                url.startsWith('/organization/crew-planning') ||
                url.startsWith('/organization/crew-operations'),
        });
    }

    if (has(permissions, 'attendance.records.view')) {
        links.push({
            title: 'Attendance',
            href: '/attendance/records',
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
