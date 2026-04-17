import {
    Command,
    Building2,
    CalendarDays,
    ClipboardList,
    LayoutDashboard,
    Landmark,
    Layers,
    LifeBuoy,
    MapPin,
    NotebookTabs,
    Receipt,
    Shield,
    Timer,
    Users,
    Wallet,
    Palette,
    Settings,
    BriefcaseBusiness,
    FileText,
    IdCard,
    BadgeCheck,
    Activity,
    CalendarCheck2,
    PiggyBank,
    Globe2,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editSecurity } from '@/routes/security';
import type { SidebarData } from '../types';

const placeholder = (key: string) =>
    `${dashboard.url()}?module=${encodeURIComponent(key)}`;

const baseSidebarData: SidebarData = {
    teams: [
        {
            name: 'OMS-HRM',
            logo: Command,
            plan: 'Human Resources',
        },
    ],
    navGroups: [
        {
            title: 'General',
            items: [
                {
                    title: 'Dashboard',
                    url: dashboard.url(),
                    icon: LayoutDashboard,
                },
                {
                    title: 'Settings',
                    icon: Settings,
                    items: [
                        {
                            title: 'Security',
                            url: editSecurity.url(),
                            icon: Shield,
                        },
                        {
                            title: 'Appearance',
                            url: editAppearance.url(),
                            icon: Palette,
                        },
                        {
                            title: 'Countries',
                            url: '/settings/master-data/countries',
                            icon: Globe2,
                        },
                        {
                            title: 'Currencies',
                            url: '/settings/master-data/currencies',
                            icon: Wallet,
                        },
                        {
                            title: 'Visa types',
                            url: '/settings/master-data/visa-types',
                            icon: IdCard,
                        },
                        {
                            title: 'Religions',
                            url: '/settings/master-data/religions',
                            icon: BadgeCheck,
                        },
                        {
                            title: 'Genders',
                            url: '/settings/master-data/genders',
                            icon: Users,
                        },
                        {
                            title: 'Banks',
                            url: '/settings/master-data/banks',
                            icon: PiggyBank,
                        },
                        {
                            title: 'Document types',
                            url: '/settings/master-data/document-types',
                            icon: FileText,
                        },
                    ],
                },
            ],
        },
        {
            title: 'Organization',
            items: [
                { title: 'Companies', url: '/organization/companies', icon: Building2 },
                { title: 'Branches', url: '/organization/branches', icon: MapPin },
                { title: 'Departments', url: '/organization/departments', icon: Layers },
                { title: 'Positions', url: '/organization/positions', icon: Landmark },
                { title: 'Activity logs', url: '/organization/activity-logs', icon: Activity },
                { title: 'Roles & permissions', url: '/organization/roles', icon: BadgeCheck },
                { title: 'Users', url: '/organization/users', icon: Users },
            ],
        },
        {
            title: 'Employees',
            items: [
                { title: 'Employee directory', url: '/organization/employees', icon: Users },
                { title: 'Documents', url: placeholder('employees.documents'), icon: FileText },
            ],
        },
        {
            title: 'Recruitment',
            items: [
                { title: 'Job postings', url: placeholder('recruitment.job-postings'), icon: BriefcaseBusiness },
                { title: 'Candidates', url: placeholder('recruitment.candidates'), icon: Users },
                { title: 'Interviews', url: placeholder('recruitment.interviews'), icon: NotebookTabs },
                { title: 'Offers', url: placeholder('recruitment.offers'), icon: ClipboardList },
            ],
        },
        {
            title: 'Onboarding',
            items: [
                { title: 'Templates', url: '/onboarding/templates', icon: ClipboardList },
                { title: 'Offboarding records', url: placeholder('offboarding.records'), icon: LifeBuoy },
            ],
        },
        {
            title: 'Attendance',
            items: [
                { title: 'Shifts', url: placeholder('attendance.shifts'), icon: Timer },
                { title: 'Employee shifts', url: placeholder('attendance.employee-shifts'), icon: CalendarDays },
                { title: 'Attendance records', url: placeholder('attendance.records'), icon: CalendarCheck2 },
                { title: 'Public holidays', url: placeholder('attendance.public-holidays'), icon: CalendarDays },
            ],
        },
        {
            title: 'Leave',
            items: [
                { title: 'Leave types', url: placeholder('leave.types'), icon: IdCard },
                { title: 'Leave balances', url: placeholder('leave.balances'), icon: Wallet },
                { title: 'Leave requests', url: placeholder('leave.requests'), icon: CalendarCheck2 },
            ],
        },
        {
            title: 'Payroll',
            items: [
                { title: 'Payroll periods', url: placeholder('payroll.periods'), icon: Receipt },
                { title: 'Payroll records', url: placeholder('payroll.records'), icon: PiggyBank },
                { title: 'Salary adjustments', url: placeholder('payroll.adjustments'), icon: Wallet },
            ],
        },
    ],
};

function has(permissions: string[], permission: string): boolean {
    return permissions.includes(permission);
}

export function getSidebarData(permissions: string[]): SidebarData {
    const groups = baseSidebarData.navGroups
        .map((group) => {
            const items = group.items
                .map((item) => {
                    if ('url' in item && item.url === '/onboarding/templates') {
                        return has(permissions, 'onboarding.templates.view') ? item : null;
                    }

                    if ('items' in item && item.items) {
                        const filteredSub = item.items.filter((sub) => {
                            if (sub.url === editSecurity.url()) {
                                return has(permissions, 'settings.security.view');
                            }

                            if (sub.url === editAppearance.url()) {
                                return has(permissions, 'settings.appearance.view');
                            }

                            if (sub.url === '/settings/master-data/countries') {
                                return has(permissions, 'settings.master-data.countries.view');
                            }

                            if (sub.url === '/settings/master-data/currencies') {
                                return has(permissions, 'settings.master-data.currencies.view');
                            }

                            if (sub.url === '/settings/master-data/visa-types') {
                                return has(permissions, 'settings.master-data.visa-types.view');
                            }

                            if (sub.url === '/settings/master-data/religions') {
                                return has(permissions, 'settings.master-data.religions.view');
                            }

                            if (sub.url === '/settings/master-data/genders') {
                                return has(permissions, 'settings.master-data.genders.view');
                            }

                            if (sub.url === '/settings/master-data/banks') {
                                return has(permissions, 'settings.master-data.banks.view');
                            }

                            if (sub.url === '/settings/master-data/document-types') {
                                return has(permissions, 'settings.master-data.document-types.view');
                            }

                            return true;
                        });

                        if (!filteredSub.length) {
                            return null;
                        }

                        return {
                            ...item,
                            items: filteredSub,
                        };
                    }

                    if (!('url' in item) || !item.url) {
                        return item;
                    }

                    switch (item.url) {
                        case '/organization/companies':
                            return has(permissions, 'companies.view') ? item : null;
                        case '/organization/branches':
                            return has(permissions, 'branches.view') ? item : null;
                        case '/organization/departments':
                            return has(permissions, 'departments.view') ? item : null;
                        case '/organization/positions':
                            return has(permissions, 'positions.view') ? item : null;
                        case '/organization/employees':
                            return has(permissions, 'employees.view') ? item : null;
                        case placeholder('employees.documents'):
                            return has(permissions, 'employees.view') ? item : null;
                        case '/organization/roles':
                            return has(permissions, 'roles.view') ? item : null;
                        case '/organization/users':
                            return has(permissions, 'users.view') ? item : null;
                        case '/organization/activity-logs':
                            return has(permissions, 'audit.view') ? item : null;
                        default:
                            return item;
                    }
                })
                .filter(Boolean);

            if (!items.length) {
                return null;
            }

            return {
                ...group,
                items,
            };
        })
        .filter(Boolean);

    return {
        ...baseSidebarData,
        navGroups: groups,
    } as SidebarData;
}

export const sidebarData = baseSidebarData;
