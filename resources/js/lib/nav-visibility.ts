export type NavPlatformAccess = {
    view: boolean;
    manage: boolean;
    database: boolean;
};

export const NO_PLATFORM_ACCESS: NavPlatformAccess = {
    view: false,
    manage: false,
    database: false,
};

export function has(permissions: string[], permission: string): boolean {
    return permissions.includes(permission);
}

export function canViewCrewOperationsOverview(permissions: string[]): boolean {
    return has(permissions, 'crew_operations.overview.view');
}

export function canViewCrewOperations(permissions: string[]): boolean {
    return (
        canViewCrewOperationsOverview(permissions) ||
        has(permissions, 'crew_operations.assignments.view') ||
        has(permissions, 'crew_operations.vessels.view') ||
        has(permissions, 'crew_operations.vessel_manning.view') ||
        has(permissions, 'crew_operations.planning.view') ||
        has(permissions, 'crew_operations.corrections.view')
    );
}

export function canAccessSalaryInputs(permissions: string[]): boolean {
    return (
        has(permissions, 'payroll.salary_inputs.view') ||
        has(permissions, 'payroll.periods.update')
    );
}

export function canViewPayroll(permissions: string[]): boolean {
    return (
        has(permissions, 'payroll.overview.view') ||
        has(permissions, 'payroll.periods.view') ||
        has(permissions, 'payroll.crew_timesheets.view') ||
        has(permissions, 'payroll.records.view') ||
        canAccessSalaryInputs(permissions)
    );
}

export function canOpenApplicationSettings(
    permissions: string[],
    platform: NavPlatformAccess = NO_PLATFORM_ACCESS,
): boolean {
    return platform.view;
}

/** Keep in sync with App\Support\Settings\SettingsHubAccess::viewPermissions() */
export const SETTINGS_HUB_VIEW_PERMISSIONS: readonly string[] = [
    'settings.security.view',
    'settings.appearance.view',
    'settings.integrations.hikvision.view',
    'settings.master-data.countries.view',
    'settings.master-data.currencies.view',
    'settings.master-data.visa-types.view',
    'settings.master-data.company-visa-types.view',
    'settings.master-data.approval-locations.view',
    'settings.master-data.sssa-options.view',
    'settings.master-data.religions.view',
    'settings.master-data.genders.view',
    'settings.master-data.courses.view',
    'settings.master-data.banks.view',
    'settings.master-data.vessel-types.view',
    'settings.master-data.vessels.view',
    'settings.master-data.ranks.view',
    'settings.master-data.clients.view',
    'settings.master-data.document-types.view',
    'settings.master-data.projects.view',
];

export function hasSettingsAccess(
    permissions: string[],
    platform: NavPlatformAccess = NO_PLATFORM_ACCESS,
): boolean {
    return (
        platform.view ||
        SETTINGS_HUB_VIEW_PERMISSIONS.some((permission) =>
            has(permissions, permission),
        )
    );
}

export function crewOperationsHref(permissions: string[]): string {
    if (has(permissions, 'crew_operations.overview.view')) {
        return '/organization/crew-operations';
    }

    if (has(permissions, 'crew_operations.planning.view')) {
        return '/organization/crew-planning';
    }

    if (has(permissions, 'crew_operations.vessels.view')) {
        return '/organization/vessels';
    }

    if (has(permissions, 'crew_operations.vessel_manning.view')) {
        return '/organization/vessel-manning';
    }

    if (has(permissions, 'crew_operations.assignments.view')) {
        return '/organization/crew';
    }

    if (has(permissions, 'crew_operations.corrections.view')) {
        return '/organization/crew-movement-corrections';
    }

    return '/organization/crew-operations';
}

export function payrollHref(permissions: string[]): string {
    if (
        has(permissions, 'payroll.periods.view') ||
        has(permissions, 'payroll.crew_timesheets.view')
    ) {
        return '/payroll';
    }

    if (has(permissions, 'payroll.overview.view')) {
        return '/payroll/overview';
    }

    if (has(permissions, 'payroll.records.view')) {
        return '/payroll/records';
    }

    if (canAccessSalaryInputs(permissions)) {
        return '/payroll/salary-inputs';
    }

    return '/payroll/overview';
}

export function attendanceHref(permissions: string[]): string | null {
    if (has(permissions, 'attendance.records.view')) {
        return '/attendance/records';
    }

    if (has(permissions, 'attendance.overview.view')) {
        return '/attendance/overview';
    }

    return null;
}

type DestinationRule = (
    permissions: string[],
    platform: NavPlatformAccess,
) => boolean;

const SIDEBAR_DESTINATION_RULES: Record<string, DestinationRule> = {
    '/organization/companies': (permissions) =>
        has(permissions, 'companies.view'),
    '/organization/branches': (permissions) =>
        has(permissions, 'branches.view'),
    '/organization/announcements': (permissions) =>
        has(permissions, 'announcements.view'),
    '/organization/departments': (permissions) =>
        has(permissions, 'departments.view'),
    '/organization/positions': (permissions) =>
        has(permissions, 'positions.view'),
    '/organization/employees': (permissions) =>
        has(permissions, 'employees.view'),
    '/organization/crew-operations': (permissions) =>
        canViewCrewOperationsOverview(permissions),
    '/organization/crew': (permissions) =>
        has(permissions, 'crew_operations.assignments.view'),
    '/organization/vessels': (permissions) =>
        has(permissions, 'crew_operations.vessels.view'),
    '/organization/vessel-manning': (permissions) =>
        has(permissions, 'crew_operations.vessel_manning.view'),
    '/organization/crew-planning': (permissions) =>
        has(permissions, 'crew_operations.planning.view'),
    '/organization/crew-operations/settings': (permissions) =>
        has(permissions, 'crew_operations.planning.view'),
    '/organization/crew-movement-corrections': (permissions) =>
        has(permissions, 'crew_operations.corrections.view'),
    '/organization/reports/crew-movement-history': (permissions) =>
        has(permissions, 'reports.crew_movement_history.view'),
    '/organization/documents': (permissions) =>
        has(permissions, 'documents.view'),
    '/organization/documents/bulk': (permissions) =>
        has(permissions, 'bulk_documents.view'),
    '/organization/contracts': (permissions) =>
        has(permissions, 'contracts.view'),
    '/organization/bank-accounts': (permissions) =>
        has(permissions, 'bank_accounts.view'),
    '/organization/training': (permissions) =>
        has(permissions, 'training.view'),
    '/organization/sea-services': (permissions) =>
        has(permissions, 'sea_services.view'),
    '/organization/roles': (permissions) => has(permissions, 'roles.view'),
    '/organization/users': (permissions) => has(permissions, 'users.view'),
    '/organization/activity-logs': (permissions) =>
        has(permissions, 'audit.view'),
    '/organization/templates/employee-profile': (permissions) =>
        has(permissions, 'employee_profile_templates.view'),
    '/hikvision/persons': (permissions) =>
        has(permissions, 'hikvision.persons.view'),
    '/hikvision/access-events': (permissions) =>
        has(permissions, 'hikvision.events.view'),
    '/attendance/calendar': (permissions) =>
        has(permissions, 'attendance.leave-requests.view'),
    '/attendance/types': (permissions) =>
        has(permissions, 'attendance.types.view'),
    '/attendance/leave-approval-policies': (permissions) =>
        has(permissions, 'attendance.leave-approval-policies.view'),
    '/attendance/leave-requests': (permissions) =>
        has(permissions, 'attendance.leave-requests.view'),
    '/attendance/records': (permissions) =>
        has(permissions, 'attendance.records.view'),
    '/attendance/overview': (permissions) =>
        has(permissions, 'attendance.overview.view'),
    '/payroll/overview': (permissions) =>
        has(permissions, 'payroll.overview.view'),
    '/payroll': (permissions) =>
        has(permissions, 'payroll.periods.view') ||
        has(permissions, 'payroll.crew_timesheets.view'),
    '/payroll/records': (permissions) =>
        has(permissions, 'payroll.records.view'),
    '/payroll/salary-inputs': (permissions) =>
        canAccessSalaryInputs(permissions),
    '/log': (_permissions, platform) => platform.view,
    '/jobs': (_permissions, platform) => platform.view,
    '/mysql': (_permissions, platform) => platform.database,
};

export function isSidebarUrlVisible(
    url: string,
    permissions: string[],
    platform: NavPlatformAccess = NO_PLATFORM_ACCESS,
): boolean {
    const rule = SIDEBAR_DESTINATION_RULES[url];

    if (!rule) {
        return true;
    }

    return rule(permissions, platform);
}

export function visibleGroupUrls(
    urls: string[],
    permissions: string[],
    platform: NavPlatformAccess = NO_PLATFORM_ACCESS,
): string[] {
    return urls.filter((url) =>
        isSidebarUrlVisible(url, permissions, platform),
    );
}
