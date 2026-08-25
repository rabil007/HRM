import { router } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Briefcase,
    Building2,
    ChevronRight,
    Clock,
    FileText,
    Ship,
    Star,
    Users,
    Wallet,
    Waves,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import React from 'react';
import { getSidebarData } from '@/components/layout/data/sidebar-data';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useSearch } from '@/context/search-provider';
import { useGlobalSearch } from '@/hooks/use-global-search';
import { useNavigationFavorites } from '@/hooks/use-navigation-favorites';
import { useRecentItems } from '@/hooks/use-recent-items';
import {
    commandResultValue,
    filterCommandGroupsForQuery,
    filterFavoritesForQuery,
    recordSearchEmptyMessage,
    shouldUseCmdkClientFilter,
} from '@/lib/global-search';
import { excludeUrlsFromNavGroups } from '@/lib/navigation-favorites';
import {
    matchingRecentItems,
    recentItemCommandValue,
    recentItemHeading,
    shouldRenderRecentGroup,
} from '@/lib/recent-items';
import type { Auth } from '@/types/auth';

const RECORD_ICONS: Record<string, LucideIcon> = {
    employees: Users,
    documents: FileText,
    crew: Waves,
    vessels: Ship,
    payroll: Wallet,
    departments: Building2,
    positions: Briefcase,
};

export function CommandMenu() {
    const { open, setOpen } = useSearch();
    const { query, setQuery, reset, recordGroups, loading, error } =
        useGlobalSearch();
    const { accessibleItems } = useNavigationFavorites();
    const recentItems = useRecentItems(open);
    const { auth } = usePage().props as unknown as {
        auth?: Auth;
    };

    const sidebarData = React.useMemo(
        () => getSidebarData(auth?.permissions ?? [], auth?.platform),
        [auth?.permissions, auth?.platform],
    );
    const commandGroups = React.useMemo(() => {
        const favoriteUrls = new Set(accessibleItems.map((item) => item.url));

        return excludeUrlsFromNavGroups(sidebarData.navGroups, favoriteUrls);
    }, [accessibleItems, sidebarData.navGroups]);
    const visibleFavorites = React.useMemo(
        () => filterFavoritesForQuery(accessibleItems, query),
        [accessibleItems, query],
    );
    const visibleRecents = React.useMemo(
        () => matchingRecentItems(recentItems, query),
        [query, recentItems],
    );
    const visibleCommandGroups = React.useMemo(
        () => filterCommandGroupsForQuery(commandGroups, query),
        [commandGroups, query],
    );

    const runCommand = React.useCallback(
        (command: () => unknown) => {
            reset();
            setOpen(false);
            command();
        },
        [reset, setOpen],
    );

    return (
        <CommandDialog
            modal
            open={open}
            shouldFilter={shouldUseCmdkClientFilter(query)}
            onOpenChange={(next) => {
                if (!next) {
                    reset();
                }

                setOpen(next);
            }}
            description="Search employees, documents, crew, and commands"
        >
            <CommandInput
                placeholder="Search employees, documents, crew..."
                value={query}
                onValueChange={setQuery}
            />
            <CommandList>
                <ScrollArea type="hover" className="h-72 pe-1">
                    <CommandEmpty>
                        {recordSearchEmptyMessage({ loading, error })}
                    </CommandEmpty>
                    {visibleFavorites.length > 0 ? (
                        <CommandGroup heading="Favorites">
                            {visibleFavorites.map((item) => (
                                <CommandItem
                                    key={item.key}
                                    value={item.title}
                                    onSelect={() => {
                                        runCommand(() => {
                                            router.visit(item.url);
                                        });
                                    }}
                                >
                                    <Star className="size-4 fill-current text-primary" />
                                    {item.title}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    ) : null}
                    {shouldRenderRecentGroup(query, recentItems) ? (
                        <CommandGroup heading="Recent">
                            {visibleRecents.map((item) => (
                                <CommandItem
                                    key={item.id}
                                    value={recentItemCommandValue(item)}
                                    onSelect={() => {
                                        runCommand(() => {
                                            router.visit(item.href);
                                        });
                                    }}
                                >
                                    <Clock className="size-4 text-muted-foreground" />
                                    <div className="flex min-w-0 flex-col">
                                        <span className="truncate">
                                            {recentItemHeading(item)}
                                        </span>
                                        {item.subtitle !== '' ? (
                                            <span className="truncate text-xs text-muted-foreground">
                                                {item.subtitle}
                                            </span>
                                        ) : null}
                                    </div>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    ) : null}
                    {recordGroups.map((group) => {
                        const Icon = RECORD_ICONS[group.key] ?? ArrowRight;

                        return (
                            <CommandGroup key={group.key} heading={group.label}>
                                {group.results.map((result) => (
                                    <CommandItem
                                        key={result.id}
                                        value={commandResultValue(
                                            query,
                                            result,
                                        )}
                                        onSelect={() => {
                                            runCommand(() => {
                                                router.visit(result.href);
                                            });
                                        }}
                                    >
                                        <Icon className="size-4" />
                                        <div className="flex min-w-0 flex-col">
                                            <span className="truncate">
                                                {result.title}
                                            </span>
                                            {result.subtitle !== '' ? (
                                                <span className="truncate text-xs text-muted-foreground">
                                                    {result.subtitle}
                                                </span>
                                            ) : null}
                                        </div>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        );
                    })}
                    {visibleCommandGroups.map((group) => (
                        <CommandGroup key={group.title} heading={group.title}>
                            {group.items.map((navItem, i) => {
                                if (navItem.url) {
                                    return (
                                        <CommandItem
                                            key={`${navItem.url}-${i}`}
                                            value={navItem.title}
                                            onSelect={() => {
                                                runCommand(() => {
                                                    router.visit(
                                                        navItem.url as string,
                                                    );
                                                });
                                            }}
                                        >
                                            <div className="flex size-4 items-center justify-center">
                                                <ArrowRight className="size-2 text-muted-foreground/80" />
                                            </div>
                                            {navItem.title}
                                        </CommandItem>
                                    );
                                }

                                return navItem.items?.map((subItem, j) => (
                                    <CommandItem
                                        key={`${navItem.title}-${subItem.url}-${j}`}
                                        value={`${navItem.title}-${subItem.url}`}
                                        onSelect={() => {
                                            runCommand(() => {
                                                router.visit(subItem.url);
                                            });
                                        }}
                                    >
                                        <div className="flex size-4 items-center justify-center">
                                            <ArrowRight className="size-2 text-muted-foreground/80" />
                                        </div>
                                        {navItem.title} <ChevronRight />{' '}
                                        {subItem.title}
                                    </CommandItem>
                                ));
                            })}
                        </CommandGroup>
                    ))}
                </ScrollArea>
            </CommandList>
        </CommandDialog>
    );
}
