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
    Ship,
    SlidersHorizontal,
    Users,
    Wallet,
} from 'lucide-react';
import { edit as hikvisionIntegrationSettings } from '@/routes/integrations/hikvision';

export type SettingsNavItem = {
    title: string;
    href: string;
    permission: string;
    icon: LucideIcon;
    color?: string;
};

export const SETTINGS_SYSTEM_ITEMS: SettingsNavItem[] = [
    {
        title: 'Application',
        href: '/settings/application',
        permission: 'settings.application.view',
        icon: SlidersHorizontal,
        color: 'bg-primary/10 text-primary',
    },
    {
        title: 'WhatsApp templates',
        href: '/settings/application/whatsapp-templates',
        permission: 'settings.integrations.whatsapp-templates.view',
        icon: MessageCircle,
        color: 'bg-green-500/10 text-green-600',
    },
    {
        title: 'Email templates',
        href: '/settings/application/email-templates',
        permission: 'settings.integrations.email-templates.view',
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
        href: hikvisionIntegrationSettings.url(),
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
        title: 'Vessels',
        href: '/settings/master-data/vessels',
        permission: 'settings.master-data.vessels.view',
        icon: Ship,
        color: 'bg-sky-500/10 text-sky-700',
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

/** Keep in sync with App\Support\Settings\SettingsHubAccess::viewPermissions() */
export const SETTINGS_VIEW_PERMISSIONS: string[] = [
    ...SETTINGS_SYSTEM_ITEMS.map((item) => item.permission),
    ...SETTINGS_INTEGRATION_ITEMS.map((item) => item.permission),
    'settings.integrations.whatsapp.view',
    'settings.integrations.whatsapp-templates.view',
    'settings.integrations.email-templates.view',
    ...SETTINGS_MASTER_DATA_ITEMS.map((item) => item.permission),
];

export function filterSettingsNavItems(
    items: SettingsNavItem[],
    permissions: string[],
): SettingsNavItem[] {
    return items.filter((item) => permissions.includes(item.permission));
}

export function hasSettingsAccess(permissions: string[]): boolean {
    return SETTINGS_VIEW_PERMISSIONS.some((permission) =>
        permissions.includes(permission),
    );
}

export function getSettingsSidebarSubItems(permissions: string[]): {
    title: string;
    url: string;
    icon: LucideIcon;
}[] {
    const systemItems = filterSettingsNavItems(
        SETTINGS_SYSTEM_ITEMS,
        permissions,
    );
    const integrationItems = filterSettingsNavItems(
        SETTINGS_INTEGRATION_ITEMS,
        permissions,
    );

    if (!hasSettingsAccess(permissions)) {
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
