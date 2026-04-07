import {
    Command,
    LayoutDashboard,
    Palette,
    Settings,
    Shield,
    UserCog,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { SidebarData } from '../types';

export const sidebarData: SidebarData = {
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
                            title: 'Profile',
                            url: editProfile.url(),
                            icon: UserCog,
                        },
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
                    ],
                },
            ],
        },
    ],
};
