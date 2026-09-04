export type NavigationDestination = {
    key: string;
    label: string;
    href: string;
    group: string;
};

/**
 * Keep keys and hrefs in sync with App\Support\Navigation\NavigationDestinationCatalog
 * and Phase 3A sidebar / nav-visibility destinations.
 */
export const NAVIGATION_DESTINATIONS: readonly NavigationDestination[] = [
    {
        key: 'dashboard',
        label: 'Dashboard',
        href: '/dashboard',
        group: 'General',
    },
    {
        key: 'organization.companies',
        label: 'Companies',
        href: '/organization/companies',
        group: 'Organization',
    },
    {
        key: 'organization.branches',
        label: 'Branches',
        href: '/organization/branches',
        group: 'Organization',
    },
    {
        key: 'organization.announcements',
        label: 'Announcements',
        href: '/organization/announcements',
        group: 'Organization',
    },
    {
        key: 'organization.departments',
        label: 'Departments',
        href: '/organization/departments',
        group: 'Organization',
    },
    {
        key: 'organization.positions',
        label: 'Positions',
        href: '/organization/positions',
        group: 'Organization',
    },
    {
        key: 'organization.activity-logs',
        label: 'Activity logs',
        href: '/organization/activity-logs',
        group: 'Organization',
    },
    {
        key: 'organization.roles',
        label: 'Roles & permissions',
        href: '/organization/roles',
        group: 'Organization',
    },
    {
        key: 'organization.users',
        label: 'Users',
        href: '/organization/users',
        group: 'Organization',
    },
    {
        key: 'organization.employee-templates',
        label: 'Employee templates',
        href: '/organization/templates/employee-profile',
        group: 'Organization',
    },
    {
        key: 'employees',
        label: 'Employees',
        href: '/organization/employees',
        group: 'Employees',
    },
    {
        key: 'documents',
        label: 'Overview',
        href: '/organization/documents',
        group: 'Documents',
    },
    {
        key: 'documents.library',
        label: 'Library',
        href: '/organization/documents/library',
        group: 'Documents',
    },
    {
        key: 'documents.templates',
        label: 'Templates',
        href: '/organization/documents/templates',
        group: 'Documents',
    },
    {
        key: 'documents.bulk',
        label: 'Generate & Track',
        href: '/organization/documents/generate',
        group: 'Documents',
    },
    {
        key: 'documents.requests',
        label: 'My Tasks',
        href: '/organization/documents/requests',
        group: 'Documents',
    },
    {
        key: 'documents.configuration',
        label: 'Document Types',
        href: '/organization/documents/configuration',
        group: 'Documents',
    },
    {
        key: 'documents.activity',
        label: 'Activity',
        href: '/organization/documents/activity',
        group: 'Documents',
    },
    {
        key: 'contracts',
        label: 'Contracts',
        href: '/organization/contracts',
        group: 'Employees',
    },
    {
        key: 'bank-accounts',
        label: 'Bank Accounts',
        href: '/organization/bank-accounts',
        group: 'Employees',
    },
    {
        key: 'training',
        label: 'Training',
        href: '/organization/training',
        group: 'Employees',
    },
    {
        key: 'sea-services',
        label: 'Sea Service',
        href: '/organization/sea-services',
        group: 'Employees',
    },
    {
        key: 'crew.overview',
        label: 'Overview',
        href: '/organization/crew-operations',
        group: 'Crew Operations',
    },
    {
        key: 'crew.current',
        label: 'Crew Assignments',
        href: '/organization/crew',
        group: 'Crew Operations',
    },
    {
        key: 'crew.planning',
        label: 'Planning',
        href: '/organization/crew-planning',
        group: 'Crew Operations',
    },
    {
        key: 'crew.vessels',
        label: 'Vessels',
        href: '/organization/vessels',
        group: 'Crew Operations',
    },
    {
        key: 'crew.corrections',
        label: 'Movement Corrections',
        href: '/organization/crew-movement-corrections',
        group: 'Crew Operations',
    },
    {
        key: 'crew.settings',
        label: 'Settings',
        href: '/organization/crew-operations/settings',
        group: 'Crew Operations',
    },
    {
        key: 'reports.crew-movement-history',
        label: 'Crew Movement History',
        href: '/organization/reports/crew-movement-history',
        group: 'Reports',
    },
    {
        key: 'hikvision.persons',
        label: 'Persons',
        href: '/hikvision/persons',
        group: 'Hikvision',
    },
    {
        key: 'hikvision.access-events',
        label: 'Access Events',
        href: '/hikvision/access-events',
        group: 'Hikvision',
    },
    {
        key: 'attendance.overview',
        label: 'Overview',
        href: '/attendance/overview',
        group: 'Attendance',
    },
    {
        key: 'attendance.calendar',
        label: 'Calendar',
        href: '/attendance/calendar',
        group: 'Attendance',
    },
    {
        key: 'leave.requests',
        label: 'Leave requests',
        href: '/attendance/leave-requests',
        group: 'Attendance',
    },
    {
        key: 'attendance.records',
        label: 'Attendance records',
        href: '/attendance/records',
        group: 'Attendance',
    },
    {
        key: 'attendance.types',
        label: 'Types',
        href: '/attendance/types',
        group: 'Attendance',
    },
    {
        key: 'attendance.approval-policies',
        label: 'Approval policies',
        href: '/attendance/leave-approval-policies',
        group: 'Attendance',
    },
    {
        key: 'payroll.overview',
        label: 'Overview',
        href: '/payroll/overview',
        group: 'Payroll',
    },
    { key: 'payroll', label: 'Payroll', href: '/payroll', group: 'Payroll' },
    {
        key: 'payroll.records',
        label: 'Payroll records',
        href: '/payroll/records',
        group: 'Payroll',
    },
    {
        key: 'payroll.salary-inputs',
        label: 'Salary inputs',
        href: '/payroll/salary-inputs',
        group: 'Payroll',
    },
    { key: 'platform.logs', label: 'Logs', href: '/log', group: 'Platform' },
    { key: 'platform.jobs', label: 'Jobs', href: '/jobs', group: 'Platform' },
    {
        key: 'platform.database',
        label: 'Database',
        href: '/mysql',
        group: 'Platform',
    },
];

export function findNavigationDestination(
    key: string,
): NavigationDestination | undefined {
    return NAVIGATION_DESTINATIONS.find(
        (destination) => destination.key === key,
    );
}

export type FavoriteNavItem = {
    key: string;
    title: string;
    url: string;
    group: string;
};

type FlattenableNavItem = {
    title: string;
    url?: string;
    icon?: unknown;
    items?: Array<{ title: string; url: string; icon?: unknown }>;
};

export function pathnameFromPageUrl(url: string): string {
    const [path = url] = url.split('?');

    if (path.length > 1 && path.endsWith('/')) {
        return path.slice(0, -1);
    }

    return path;
}

export function destinationKeyFromPathname(pathname: string): string | null {
    return destinationKeyFromPageUrl(pathname);
}

export function destinationKeyFromPageUrl(url: string): string | null {
    const [rawPath = url, search = ''] = url.split('?');
    const normalized = pathnameFromPageUrl(rawPath);

    if (
        normalized === '/organization/documents/bulk' ||
        normalized.startsWith('/organization/documents/bulk/')
    ) {
        const view = new URLSearchParams(search).get('view');

        if (view === 'history') {
            return 'documents.activity';
        }

        return 'documents.bulk';
    }

    if (
        normalized === '/organization/documents/employees' ||
        normalized.startsWith('/organization/documents/employees/')
    ) {
        return 'documents.library';
    }

    if (normalized === '/organization/documents') {
        return 'documents';
    }

    if (
        normalized === '/organization/vessel-manning' ||
        normalized.startsWith('/organization/vessel-manning/')
    ) {
        return 'crew.vessels';
    }

    let match: { key: string; href: string } | null = null;

    for (const destination of NAVIGATION_DESTINATIONS) {
        const isExact = normalized === destination.href;
        const isNested = normalized.startsWith(`${destination.href}/`);

        if (!isExact && !isNested) {
            continue;
        }

        if (match === null || destination.href.length > match.href.length) {
            match = { key: destination.key, href: destination.href };
        }
    }

    return match?.key ?? null;
}

export function isFavoriteDestinationKey(
    keys: readonly string[],
    key: string | null,
): boolean {
    return key !== null && keys.includes(key);
}

export function resolveAccessibleFavoriteItems(
    keys: readonly string[],
    isDestinationVisible: (href: string) => boolean,
): FavoriteNavItem[] {
    const seen = new Set<string>();

    return keys.flatMap((key) => {
        if (seen.has(key)) {
            return [];
        }

        seen.add(key);

        const destination = findNavigationDestination(key);

        if (!destination) {
            return [];
        }

        if (!isDestinationVisible(destination.href)) {
            return [];
        }

        return [
            {
                key: destination.key,
                title: destination.label,
                url: destination.href,
                group: destination.group,
            },
        ];
    });
}

export function flattenSidebarNavLinks(
    groups: Array<{ items: FlattenableNavItem[] }>,
): Map<string, FlattenableNavItem & { url: string }> {
    const links = new Map<string, FlattenableNavItem & { url: string }>();

    for (const group of groups) {
        for (const item of group.items) {
            if (item.url) {
                links.set(item.url, { ...item, url: item.url });
            }

            for (const subItem of item.items ?? []) {
                links.set(subItem.url, subItem);
            }
        }
    }

    return links;
}

export function excludeUrlsFromNavGroups<
    T extends {
        title: string;
        items: Array<{
            title: string;
            url?: string;
            items?: Array<{ title: string; url: string }>;
        }>;
    },
>(groups: T[], excludedUrls: ReadonlySet<string>): T[] {
    return groups.flatMap((group) => {
        const items = group.items.flatMap((item) => {
            if (item.url) {
                return excludedUrls.has(item.url) ? [] : [item];
            }

            const nested = (item.items ?? []).filter(
                (subItem) => !excludedUrls.has(subItem.url),
            );

            if (nested.length === 0) {
                return [];
            }

            return [{ ...item, items: nested }];
        });

        if (items.length === 0) {
            return [];
        }

        return [{ ...group, items }];
    });
}
