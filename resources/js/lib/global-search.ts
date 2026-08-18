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
                        value: item.title,
                    },
                ];
            }

            return (item.items ?? []).map((subItem, subIndex) => ({
                key: `${item.title}-${subItem.url}-${subIndex}`,
                group: group.title,
                title: `${item.title} / ${subItem.title}`,
                url: subItem.url,
                value: `${item.title}-${subItem.url}`,
            }));
        }),
    );
}
