export const GLOBAL_SEARCH_MIN_QUERY_LENGTH = 2;
export const GLOBAL_SEARCH_MAX_QUERY_LENGTH = 80;
export const GLOBAL_SEARCH_DEBOUNCE_MS = 250;

export const RECORD_GROUP_ORDER = [
    'employees',
    'documents',
    'crew',
    'vessels',
    'payroll',
    'departments',
    'positions',
] as const;

export type GlobalSearchResult = {
    id: string;
    title: string;
    subtitle: string;
    href: string;
};

export type GlobalSearchGroup = {
    key: string;
    label: string;
    results: GlobalSearchResult[];
};

export type GlobalSearchResponse = {
    groups: GlobalSearchGroup[];
};

export type FlattenedNavCommand = {
    key: string;
    group: string;
    title: string;
    url: string;
    value: string;
};

type SearchableNavItem = {
    title: string;
    url?: string;
    items?: Array<{ title: string; url: string }>;
};

type SearchableNavGroup = {
    title: string;
    items: SearchableNavItem[];
};

export function isCommandPaletteHotkey(event: {
    key: string;
    metaKey: boolean;
    ctrlKey: boolean;
}): boolean {
    return event.key === 'k' && (event.metaKey || event.ctrlKey);
}

export function shouldRequestRecordSearch(query: string): boolean {
    const trimmed = query.trim();

    return (
        trimmed.length >= GLOBAL_SEARCH_MIN_QUERY_LENGTH &&
        trimmed.length <= GLOBAL_SEARCH_MAX_QUERY_LENGTH
    );
}

export function shouldUseCmdkClientFilter(query: string): boolean {
    return !shouldRequestRecordSearch(query);
}

export function textMatchesQuery(haystack: string, query: string): boolean {
    const needle = query.trim().toLowerCase();

    if (needle === '') {
        return true;
    }

    return haystack.toLowerCase().includes(needle);
}

function pluralSearchVariants(word: string): string[] {
    const variants = new Set<string>([word]);

    if (word.endsWith('ies') && word.length > 3) {
        variants.add(`${word.slice(0, -3)}y`);
    }

    if (word.endsWith('s') && !word.endsWith('ss') && word.length > 1) {
        variants.add(word.slice(0, -1));
    }

    if (
        word.endsWith('y') &&
        word.length > 1 &&
        !['ay', 'ey', 'oy', 'uy'].some((ending) => word.endsWith(ending))
    ) {
        variants.add(`${word.slice(0, -1)}ies`);
    }

    if (!word.endsWith('s')) {
        variants.add(`${word}s`);
    }

    return [...variants];
}

export function destinationMatchesQuery(
    command: Pick<FlattenedNavCommand, 'title' | 'value'>,
    query: string,
): boolean {
    const haystack = `${command.title} ${command.value}`.toLowerCase();
    const needle = query.trim().toLowerCase();

    if (needle === '') {
        return true;
    }

    if (haystack.includes(needle)) {
        return true;
    }

    return needle
        .split(/\s+/)
        .filter(Boolean)
        .every((word) =>
            pluralSearchVariants(word).some((variant) =>
                haystack.includes(variant),
            ),
        );
}

export function commandItemSearchValue(
    groupTitle: string,
    itemTitle: string,
    nestedTitle?: string,
): string {
    return [groupTitle, itemTitle, nestedTitle]
        .filter((part): part is string => part !== undefined && part !== '')
        .join(' ');
}

export function collectNavGroupUrls(
    groups: readonly SearchableNavGroup[],
): Set<string> {
    const urls = new Set<string>();

    for (const group of groups) {
        for (const item of group.items) {
            if (item.url) {
                urls.add(item.url);
            }

            for (const subItem of item.items ?? []) {
                urls.add(subItem.url);
            }
        }
    }

    return urls;
}

export function normalizeDestinationUrl(url: string): string {
    const path = url.trim().split(/[?#]/, 1)[0] ?? '';

    if (path === '' || path === '/') {
        return '/';
    }

    return path.endsWith('/') ? path.slice(0, -1) : path;
}

export function excludeOccupiedCommandGroups<
    T extends { title: string; items: Array<{ title: string; url: string }> },
>(groups: readonly T[], occupiedUrls: ReadonlySet<string>): T[] {
    const occupied = new Set(
        [...occupiedUrls].map((url) => normalizeDestinationUrl(url)),
    );

    return groups.flatMap((group) => {
        const items = group.items.filter((item) => {
            const url = normalizeDestinationUrl(item.url);

            if (occupied.has(url)) {
                return false;
            }

            occupied.add(url);

            return true;
        });

        if (items.length === 0) {
            return [];
        }

        return [{ ...group, items }];
    });
}

export function filterFavoritesForQuery<T extends { title: string }>(
    items: readonly T[],
    query: string,
): T[] {
    if (!shouldRequestRecordSearch(query)) {
        return [...items];
    }

    return items.filter((item) => textMatchesQuery(item.title, query));
}

export function filterCommandGroupsForQuery<T extends SearchableNavGroup>(
    groups: readonly T[],
    query: string,
): T[] {
    if (!shouldRequestRecordSearch(query)) {
        return [...groups];
    }

    return groups.flatMap((group) => {
        const items = group.items.flatMap((item) => {
            if (item.url) {
                return destinationMatchesQuery(
                    {
                        title: item.title,
                        value: commandItemSearchValue(group.title, item.title),
                    },
                    query,
                )
                    ? [item]
                    : [];
            }

            const nested = (item.items ?? []).filter((subItem) =>
                destinationMatchesQuery(
                    {
                        title: `${item.title} / ${subItem.title}`,
                        value: commandItemSearchValue(
                            group.title,
                            item.title,
                            subItem.title,
                        ),
                    },
                    query,
                ),
            );

            if (nested.length === 0) {
                return [];
            }

            return [{ ...item, items: nested }];
        });

        if (items.length === 0) {
            return [];
        }

        return [{ ...group, items }];
    });
}

export function isStaleSearchResponse(
    responseId: number,
    latestRequestId: number,
): boolean {
    return responseId !== latestRequestId;
}

export function orderedRecordGroups(
    groups: GlobalSearchGroup[],
): GlobalSearchGroup[] {
    return RECORD_GROUP_ORDER.flatMap((key) => {
        const group = groups.find((candidate) => candidate.key === key);

        if (group === undefined || group.results.length === 0) {
            return [];
        }

        return [group];
    });
}

export function recordSearchEmptyMessage(options: {
    loading: boolean;
    error: boolean;
}): string {
    if (options.loading) {
        return 'Searching…';
    }

    if (options.error) {
        return 'Search failed. Try again.';
    }

    return 'No results found.';
}

export function commandResultValue(
    query: string,
    result: GlobalSearchResult,
): string {
    return `${query} ${result.title} ${result.subtitle} ${result.id}`;
}

export function flattenNavCommands(
    navGroups: SearchableNavGroup[],
): FlattenedNavCommand[] {
    return navGroups.flatMap((group) =>
        group.items.flatMap((item, itemIndex): FlattenedNavCommand[] => {
            if (item.url) {
                return [
                    {
                        key: `${item.url}-${itemIndex}`,
                        group: group.title,
                        title: item.title,
                        url: item.url,
                        value: commandItemSearchValue(group.title, item.title),
                    },
                ];
            }

            return (item.items ?? []).map((subItem, subIndex) => ({
                key: `${item.title}-${subItem.url}-${subIndex}`,
                group: group.title,
                title: `${item.title} / ${subItem.title}`,
                url: subItem.url,
                value: commandItemSearchValue(
                    group.title,
                    item.title,
                    subItem.title,
                ),
            }));
        }),
    );
}
