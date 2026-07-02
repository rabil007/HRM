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
import { cn } from '@/lib/utils';

type Team = {
    id?: number;
    name?: string | null;
    logo_url?: string | null;
};

type TeamSwitcherProps = {
    teams: Team[];
};

function resolveActiveTeam(
    teams: Team[],
    currentCompanyId: number | null,
): Team {
    if (currentCompanyId) {
        const match = teams.find((t) => t.id === currentCompanyId);

        if (match) {
            return match;
        }
    }

    return teams[0] ?? { id: undefined, name: 'No company' };
}

function TeamAvatar({ team, size = 'md' }: { team: Team; size?: 'md' | 'sm' }) {
    const [failed, setFailed] = React.useState(false);
    const initial = (team.name ?? 'C').slice(0, 1);
    const showLogo = Boolean(team.logo_url) && !failed;
    const sizeClass = size === 'sm' ? 'size-6' : 'size-8';

    return (
        <div
            className={cn(
                'flex shrink-0 items-center justify-center overflow-hidden rounded-lg',
                sizeClass,
                showLogo
                    ? 'bg-transparent'
                    : 'bg-sidebar-primary text-sidebar-primary-foreground',
            )}
        >
            {showLogo ? (
                <img
                    src={team.logo_url!}
                    alt=""
                    className="size-full object-contain"
                    onError={() => setFailed(true)}
                />
            ) : (
                <span
                    className={cn(
                        'font-bold',
                        size === 'sm' ? 'text-xs' : 'text-sm',
                    )}
                >
                    {initial}
                </span>
            )}
        </div>
    );
}

export function TeamSwitcher({ teams }: TeamSwitcherProps) {
    const { isMobile } = useSidebar();
    const { props } = usePage();
    const currentCompanyId = (props as { current_company_id?: number })
        ?.current_company_id
        ? Number((props as { current_company_id?: number }).current_company_id)
        : null;

    const [activeTeam, setActiveTeam] = React.useState<Team>(() =>
        resolveActiveTeam(teams, currentCompanyId),
    );

    React.useEffect(() => {
        setActiveTeam(resolveActiveTeam(teams, currentCompanyId));
    }, [currentCompanyId, teams]);

    React.useEffect(() => {
        if (!teams.length) {
            setActiveTeam({ id: undefined, name: 'No company' });
        }
    }, [teams]);

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        >
                            <TeamAvatar team={activeTeam} />
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
                                key={team.id ?? team.name ?? index}
                                onClick={() => {
                                    setActiveTeam(team);

                                    if (team.id) {
                                        router.post(
                                            '/organization/companies/switch',
                                            { company_id: team.id },
                                            { preserveScroll: true },
                                        );
                                    }
                                }}
                                className="gap-2 p-2"
                            >
                                <TeamAvatar team={team} size="sm" />
                                {team.name ?? 'Company'}
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
