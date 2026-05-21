import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarRail,
} from '@/components/ui/sidebar';
import { useLayout } from '@/context/layout-provider';
import { getSidebarData } from './data/sidebar-data';
import ApplicationLogo from '@/components/application-logo';
import { NavGroup } from './nav-group';
import { NavUser } from './nav-user';
import { TeamSwitcher } from './team-switcher';

export function AppSidebar() {
    const { collapsible, variant } = useLayout();
    const { company_switcher_companies: companies = [], auth } = usePage().props as unknown as {
        company_switcher_companies?: { id: number; name: string }[];
        auth?: { permissions?: string[] };
    };
    const sidebarData = useMemo(
        () => getSidebarData(auth?.permissions ?? []),
        [auth?.permissions],
    );
    const teams = useMemo(
        () => companies.map((c) => ({ id: c.id, name: c.name })),
        [companies],
    );

    return (
        <Sidebar collapsible={collapsible} variant={variant}>
            <SidebarHeader className="gap-3">
                <div className="flex items-center px-2 py-1">
                    <ApplicationLogo
                        variant="sidebar"
                        imageClassName="h-7 w-auto max-w-[140px]"
                        iconClassName="size-6 text-sidebar-foreground"
                    />
                </div>
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
