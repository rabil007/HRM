import { router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BookmarkPlus,
    ChevronRight,
    Clock3,
    SearchIcon,
    Star,
} from 'lucide-react';
import React from 'react';
import { getSidebarData } from '@/components/layout/data/sidebar-data';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useSearch } from '@/context/search-provider';
import {
    type WorkspaceShortcut,
    useWorkspaceShortcuts,
} from '@/hooks/use-workspace-shortcuts';

type GlobalSearchResult = {
    type: string;
    id: number;
    title: string;
    subtitle: string;
    href: string;
};

type SearchResponse = {
    results: GlobalSearchResult[];
};

function currentPageShortcut(url: string): WorkspaceShortcut {
    const title =
        typeof document !== 'undefined'
            ? document.title.replace(/\s+[|—-]\s+.*$/, '')
            : 'Current view';

    return {
        id: `view:${url}`,
        title: title || 'Current view',
        href: url,
        subtitle: 'Saved view',
    };
}

export function CommandMenu() {
    const { open, setOpen } = useSearch();
    const page = usePage();
    const { auth, current_company_id: currentCompanyId } = page.props as unknown as {
        auth?: {
            user?: { id?: number };
            permissions?: string[];
        };
        current_company_id?: number | null;
    };
    const sidebarData = React.useMemo(
        () => getSidebarData(auth?.permissions ?? []),
        [auth?.permissions],
    );
    const shortcuts = useWorkspaceShortcuts(
        auth?.user?.id ?? null,
        currentCompanyId ? Number(currentCompanyId) : null,
    );
    const [query, setQuery] = React.useState('');
    const [results, setResults] = React.useState<GlobalSearchResult[]>([]);
    const [isSearching, setIsSearching] = React.useState(false);

    React.useEffect(() => {
        if (!open) {
            setQuery('');
            setResults([]);
            return;
        }

        const term = query.trim();
        if (term.length < 2) {
            setResults([]);
            setIsSearching(false);
            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(() => {
            setIsSearching(true);

            void fetch(`/search?q=${encodeURIComponent(term)}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then(async (response) => {
                    if (!response.ok) {
                        return { results: [] } satisfies SearchResponse;
                    }

                    return (await response.json()) as SearchResponse;
                })
                .then((payload) => setResults(payload.results ?? []))
                .catch((error: unknown) => {
                    if (
                        !(error instanceof DOMException) ||
                        error.name !== 'AbortError'
                    ) {
                        setResults([]);
                    }
                })
                .finally(() => setIsSearching(false));
        }, 250);

        return () => {
            window.clearTimeout(timeout);
            controller.abort();
        };
    }, [open, query]);

    const runCommand = React.useCallback(
        (command: () => unknown) => {
            setOpen(false);
            command();
        },
        [setOpen],
    );

    const visit = React.useCallback(
        (item: WorkspaceShortcut) => {
            shortcuts.remember(item);
            runCommand(() => router.visit(item.href));
        },
        [runCommand, shortcuts],
    );

    const currentView = React.useMemo(
        () => currentPageShortcut(page.url),
        [page.url],
    );
    const currentIsFavorite = shortcuts.isFavorite(page.url);
    const currentIsSaved = shortcuts.savedViews.some(
        (item) => item.href === page.url,
    );

    return (
        <CommandDialog
            modal
            open={open}
            onOpenChange={setOpen}
            shouldFilter={results.length === 0}
        >
            <CommandInput
                value={query}
                onValueChange={setQuery}
                placeholder="Search employees, documents, crew, payroll or commands..."
            />
            <CommandList>
                <ScrollArea type="hover" className="h-96 pe-1">
                    <CommandEmpty>
                        {isSearching
                            ? 'Searching...'
                            : query.trim().length < 2
                              ? 'Type at least 2 characters to search records.'
                              : 'No results found.'}
                    </CommandEmpty>

                    {results.length > 0 ? (
                        <CommandGroup heading="Records">
                            {results.map((result) => (
                                <CommandItem
                                    key={`${result.type}:${result.id}`}
                                    value={`${result.title} ${result.subtitle}`}
                                    onSelect={() =>
                                        visit({
                                            id: `${result.type}:${result.id}`,
                                            title: result.title,
                                            subtitle: result.subtitle,
                                            href: result.href,
                                        })
                                    }
                                >
                                    <SearchIcon className="size-4 text-muted-foreground" />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate font-medium">
                                            {result.title}
                                        </div>
                                        {result.subtitle ? (
                                            <div className="truncate text-xs text-muted-foreground">
                                                {result.subtitle}
                                            </div>
                                        ) : null}
                                    </div>
                                    <span className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                        {result.type.replace('_', ' ')}
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    ) : null}

                    {query.trim().length < 2 ? (
                        <>
                            <CommandGroup heading="This view">
                                <CommandItem
                                    value="favorite current page"
                                    onSelect={() =>
                                        shortcuts.toggleFavorite(currentView)
                                    }
                                >
                                    <Star className="size-4" />
                                    {currentIsFavorite
                                        ? 'Remove current page from favorites'
                                        : 'Favorite current page'}
                                </CommandItem>
                                <CommandItem
                                    value="save current filtered view"
                                    onSelect={() =>
                                        currentIsSaved
                                            ? shortcuts.removeSavedView(
                                                  currentView.href,
                                              )
                                            : shortcuts.saveView(currentView)
                                    }
                                >
                                    <BookmarkPlus className="size-4" />
                                    {currentIsSaved
                                        ? 'Remove saved view'
                                        : 'Save current view and filters'}
                                </CommandItem>
                            </CommandGroup>

                            {shortcuts.favorites.length > 0 ? (
                                <CommandGroup heading="Favorites">
                                    {shortcuts.favorites.map((item) => (
                                        <CommandItem
                                            key={`favorite:${item.href}`}
                                            value={`favorite ${item.title}`}
                                            onSelect={() => visit(item)}
                                        >
                                            <Star className="size-4" />
                                            <div className="min-w-0 flex-1 truncate">
                                                {item.title}
                                            </div>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            ) : null}

                            {shortcuts.savedViews.length > 0 ? (
                                <CommandGroup heading="Saved views">
                                    {shortcuts.savedViews.map((item) => (
                                        <CommandItem
                                            key={`view:${item.href}`}
                                            value={`saved view ${item.title}`}
                                            onSelect={() => visit(item)}
                                        >
                                            <BookmarkPlus className="size-4" />
                                            <div className="min-w-0 flex-1 truncate">
                                                {item.title}
                                            </div>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            ) : null}

                            {shortcuts.recent.length > 0 ? (
                                <CommandGroup heading="Recent">
                                    {shortcuts.recent.map((item) => (
                                        <CommandItem
                                            key={`recent:${item.href}`}
                                            value={`recent ${item.title}`}
                                            onSelect={() => visit(item)}
                                        >
                                            <Clock3 className="size-4" />
                                            <div className="min-w-0 flex-1 truncate">
                                                {item.title}
                                            </div>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            ) : null}

                            <CommandSeparator />
                            {sidebarData.navGroups.map((group) => (
                                <CommandGroup
                                    key={group.title}
                                    heading={group.title}
                                >
                                    {group.items.map((navItem, i) => {
                                        if (navItem.url) {
                                            return (
                                                <CommandItem
                                                    key={`${navItem.url}-${i}`}
                                                    value={navItem.title}
                                                    onSelect={() =>
                                                        visit({
                                                            id: `nav:${navItem.url}`,
                                                            title: navItem.title,
                                                            href: navItem.url as string,
                                                        })
                                                    }
                                                >
                                                    <ArrowRight className="size-4 text-muted-foreground/80" />
                                                    {navItem.title}
                                                </CommandItem>
                                            );
                                        }

                                        return navItem.items?.map(
                                            (subItem, j) => (
                                                <CommandItem
                                                    key={`${navItem.title}-${subItem.url}-${j}`}
                                                    value={`${navItem.title} ${subItem.title}`}
                                                    onSelect={() =>
                                                        visit({
                                                            id: `nav:${subItem.url}`,
                                                            title: `${navItem.title} › ${subItem.title}`,
                                                            href: subItem.url,
                                                        })
                                                    }
                                                >
                                                    <ArrowRight className="size-4 text-muted-foreground/80" />
                                                    {navItem.title}{' '}
                                                    <ChevronRight className="size-3" />{' '}
                                                    {subItem.title}
                                                </CommandItem>
                                            ),
                                        );
                                    })}
                                </CommandGroup>
                            ))}
                        </>
                    ) : null}
                </ScrollArea>
            </CommandList>
        </CommandDialog>
    );
}
