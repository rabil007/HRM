import type { LucideIcon } from 'lucide-react';
import {
    Award,
    BadgeCheck,
    Camera,
    FileText,
    FolderKanban,
    Globe2,
    GraduationCap,
    Handshake,
    IdCard,
    LayoutGrid,
    MapPin,
    Mail,
    MessageCircle,
    Palette,
    PiggyBank,
    Sailboat,
    Shield,
    SlidersHorizontal,
    Users,
    Wallet,
} from 'lucide-react';
import { excludeOccupiedCommandGroups } from './global-search';
import {
    filterSettingsNavItems,
    hasSettingsAccess,
    NO_PLATFORM_ACCESS,
    SETTINGS_HUB_VIEW_PERMISSIONS,
} from './nav-visibility';
import type { NavPlatformAccess } from './nav-visibility';

export type SettingsNavItem = {
    title: string;
    href: string;
    permission?: string | readonly string[];
    platformOnly?: boolean;
    icon: LucideIcon;
    color?: string;
};

export const SETTINGS_SYSTEM_ITEMS: SettingsNavItem[] = [
    {
        title: 'Application',
        href: '/settings/application',
        platformOnly: true,
        icon: SlidersHorizontal,
        color: 'bg-primary/10 text-primary',
    },
    {
        title: 'WhatsApp templates',
        href: '/settings/application/whatsapp-templates',
        platformOnly: true,
        icon: MessageCircle,
        color: 'bg-green-500/10 text-green-600',
    },
    {
        title: 'Email templates',
        href: '/settings/application/email-templates',
        platformOnly: true,
        icon: Mail,
        color: 'bg-blue-500/10 text-blue-600',
    },
    {
        title: 'Security',
        href: '/settings/security',
        permission: 'settings.security.view',
        icon: Shield,
        color: 'bg-blue-500/10 text-blue-600',
    },
    {
        title: 'Appearance',
        href: '/settings/appearance',
        permission: 'settings.appearance.view',
        icon: Palette,
        color: 'bg-accent/10 text-accent',
    },
];

export const SETTINGS_INTEGRATION_ITEMS: SettingsNavItem[] = [
    {
        title: 'Hikvision',
        href: '/settings/integrations/hikvision',
        permission: 'settings.integrations.hikvision.view',
        icon: Camera,
        color: 'bg-sky-500/10 text-sky-600',
    },
];

export const SETTINGS_MASTER_DATA_ITEMS: SettingsNavItem[] = [
    {
        title: 'Countries',
        href: '/settings/master-data/countries',
        permission: 'settings.master-data.countries.view',
        icon: Globe2,
        color: 'bg-emerald-500/10 text-emerald-600',
    },
    {
        title: 'Currencies',
        href: '/settings/master-data/currencies',
        permission: 'settings.master-data.currencies.view',
        icon: Wallet,
        color: 'bg-amber-500/10 text-amber-600',
    },
    {
        title: 'Visa types',
        href: '/settings/master-data/visa-types',
        permission: 'settings.master-data.visa-types.view',
        icon: IdCard,
        color: 'bg-cyan-500/10 text-cyan-600',
    },
    {
        title: 'Sponsors',
        href: '/settings/master-data/company-visa-types',
        permission: 'settings.master-data.company-visa-types.view',
        icon: IdCard,
        color: 'bg-cyan-500/10 text-cyan-600',
    },
    {
        title: 'Approval locations',
        href: '/settings/master-data/approval-locations',
        permission: 'settings.master-data.approval-locations.view',
        icon: MapPin,
        color: 'bg-teal-500/10 text-teal-600',
    },
    {
        title: 'SSSA options',
        href: '/settings/master-data/sssa-options',
        permission: 'settings.master-data.sssa-options.view',
        icon: MapPin,
        color: 'bg-teal-500/10 text-teal-600',
    },
    {
        title: 'Religions',
        href: '/settings/master-data/religions',
        permission: 'settings.master-data.religions.view',
        icon: BadgeCheck,
        color: 'bg-primary/10 text-primary',
    },
    {
        title: 'Genders',
        href: '/settings/master-data/genders',
        permission: 'settings.master-data.genders.view',
        icon: Users,
        color: 'bg-rose-500/10 text-rose-600',
    },
    {
        title: 'Courses',
        href: '/settings/master-data/courses',
        permission: 'settings.master-data.courses.view',
        icon: GraduationCap,
        color: 'bg-lime-500/10 text-lime-600',
    },
    {
        title: 'Banks',
        href: '/settings/master-data/banks',
        permission: 'settings.master-data.banks.view',
        icon: PiggyBank,
        color: 'bg-orange-500/10 text-orange-600',
    },
    {
        title: 'Vessel types',
        href: '/settings/master-data/vessel-types',
        permission: 'settings.master-data.vessel-types.view',
        icon: Sailboat,
        color: 'bg-sky-500/10 text-sky-600',
    },
    {
        title: 'Ranks',
        href: '/settings/master-data/ranks',
        permission: 'settings.master-data.ranks.view',
        icon: Award,
        color: 'bg-accent/10 text-accent',
    },
    {
        title: 'Clients',
        href: '/settings/master-data/clients',
        permission: 'settings.master-data.clients.view',
        icon: Handshake,
        color: 'bg-teal-500/10 text-teal-600',
    },
    {
        title: 'Document types',
        href: '/settings/master-data/document-types',
        permission: 'settings.master-data.document-types.view',
        icon: FileText,
        color: 'bg-slate-500/10 text-slate-600',
    },
    {
        title: 'Projects',
        href: '/settings/master-data/projects',
        permission: 'settings.master-data.projects.view',
        icon: FolderKanban,
        color: 'bg-violet-500/10 text-violet-600',
    },
];

export const SETTINGS_VIEW_PERMISSIONS: string[] = [
    ...SETTINGS_HUB_VIEW_PERMISSIONS,
];

export type SettingsNavGroup = {
    title: string;
    description: string;
    commandHeading: string;
    items: SettingsNavItem[];
};

export const SETTINGS_NAV_GROUPS: SettingsNavGroup[] = [
    {
        title: 'System',
        description:
            'Application branding, email, WhatsApp, security, and appearance.',
        commandHeading: 'Settings · System',
        items: SETTINGS_SYSTEM_ITEMS,
    },
    {
        title: 'Integrations',
        description:
            'Company-owned integrations such as Hikvision access control.',
        commandHeading: 'Settings · Integrations',
        items: SETTINGS_INTEGRATION_ITEMS,
    },
    {
        title: 'Master data',
        description:
            'Reference data used across employees, payroll, and compliance.',
        commandHeading: 'Settings · Master Data',
        items: SETTINGS_MASTER_DATA_ITEMS,
    },
];

export function accessibleSettingsNavGroups(
    permissions: string[],
    platform: NavPlatformAccess = NO_PLATFORM_ACCESS,
): SettingsNavGroup[] {
    return SETTINGS_NAV_GROUPS.map((group) => ({
        ...group,
        items: filterSettingsNavItems(group.items, permissions, platform),
    })).filter((group) => group.items.length > 0);
}

export function extraSettingsCommandGroups(
    permissions: string[],
    platform: NavPlatformAccess = NO_PLATFORM_ACCESS,
    occupiedUrls: ReadonlySet<string> = new Set(),
): Array<{ title: string; items: Array<{ title: string; url: string }> }> {
    return excludeOccupiedCommandGroups(
        accessibleSettingsNavGroups(permissions, platform).map((group) => ({
            title: group.commandHeading,
            items: group.items.map((item) => ({
                title: item.title,
                url: item.href,
            })),
        })),
        occupiedUrls,
    );
}

export { filterSettingsNavItems, hasSettingsAccess };

export function getSettingsSidebarSubItems(
    permissions: string[],
    platform: NavPlatformAccess = NO_PLATFORM_ACCESS,
): {
    title: string;
    url: string;
    icon: LucideIcon;
}[] {
    const systemItems = filterSettingsNavItems(
        SETTINGS_SYSTEM_ITEMS,
        permissions,
        platform,
    );
    const integrationItems = filterSettingsNavItems(
        SETTINGS_INTEGRATION_ITEMS,
        permissions,
        platform,
    );

    if (!hasSettingsAccess(permissions, platform)) {
        return [];
    }

    return [
        {
            title: 'Overview',
            url: '/settings',
            icon: LayoutGrid,
        },
        ...systemItems.map((item) => ({
            title: item.title,
            url: item.href,
            icon: item.icon,
        })),
        ...integrationItems.map((item) => ({
            title: item.title,
            url: item.href,
            icon: item.icon,
        })),
    ];
}
