import { router, usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import * as React from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';

type TeamSwitcherProps = {
    teams: {
        id?: number;
        name: string;
    }[];
};

export function TeamSwitcher({ teams }: TeamSwitcherProps) {
    const { isMobile } = useSidebar();
    const [activeTeam, setActiveTeam] = React.useState(teams[0]);
    const { url, props } = usePage();
    const currentCompanyId =
        (props as any)?.company?.id && url?.startsWith('/organization/companies/')
            ? Number((props as any).company.id)
            : null;

    React.useEffect(() => {
        if (!currentCompanyId) {
            return;
        }

        const match = teams.find((t) => t.id === currentCompanyId);

        if (match) {
            setActiveTeam(match);
        }
    }, [currentCompanyId, teams]);

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        >
                            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                                <span className="text-sm font-bold">{activeTeam?.name?.slice(0, 1) ?? 'C'}</span>
                            </div>
                            <div className="grid flex-1 text-start text-sm leading-tight">
                                <span className="truncate font-semibold">
                                    {activeTeam.name}
                                </span>
                                <span className="truncate text-xs">
                                    Companies
                                </span>
                            </div>
                            <ChevronsUpDown className="ms-auto" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="start"
                        side={isMobile ? 'bottom' : 'right'}
                        sideOffset={4}
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Companies
                        </DropdownMenuLabel>
                        {teams.map((team, index) => (
                            <DropdownMenuItem
                                key={team.name}
                                onClick={() => {
                                    setActiveTeam(team);

                                    if (team.id) {
                                        router.visit(`/organization/companies/${team.id}`);
                                    } else {
                                        router.visit('/organization/companies');
                                    }
                                }}
                                className="gap-2 p-2"
                            >
                                <div className="flex size-6 items-center justify-center rounded-sm border">
                                    <span className="text-xs font-semibold">{team.name.slice(0, 1)}</span>
                                </div>
                                {team.name}
                                <DropdownMenuShortcut>
                                    ⌘{index + 1}
                                </DropdownMenuShortcut>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
