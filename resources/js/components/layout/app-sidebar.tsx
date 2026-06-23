import { usePage } from '@inertiajs/react';
import { useMemo, useEffect } from 'react';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarRail,
} from '@/components/ui/sidebar';
import { useLayout } from '@/context/layout-provider';
import { getSidebarData } from './data/sidebar-data';
import { NavGroup } from './nav-group';
import { NavUser } from './nav-user';
import { TeamSwitcher } from './team-switcher';

export function AppSidebar() {
    const { collapsible, variant } = useLayout();
    const { company_switcher_companies: companies = [], auth } = usePage().props as unknown as {
        company_switcher_companies?: { id: number; name: string; logo_url?: string | null }[];
        auth?: { permissions?: string[] };
    };
    const sidebarData = useMemo(
        () => getSidebarData(auth?.permissions ?? []),
        [auth?.permissions],
    );
    const teams = useMemo(
        () => companies.map((c) => ({ id: c.id, name: c.name, logo_url: c.logo_url ?? null })),
        [companies],
    );

    const pageUrl = usePage().url;
    useEffect(() => {
        const timeout = setTimeout(() => {
            const activeElement = document.querySelector('[data-sidebar="content"] [data-active="true"]');
            if (activeElement) {
                activeElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 100);
        return () => clearTimeout(timeout);
    }, [pageUrl]);

    return (
        <Sidebar collapsible={collapsible} variant={variant}>
            <SidebarHeader>
                <TeamSwitcher teams={teams} />
            </SidebarHeader>
            <SidebarContent>
                {sidebarData.navGroups.map((props) => (
                    <NavGroup key={props.title} {...props} />
                ))}
            </SidebarContent>
            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
    );
}
