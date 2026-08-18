export type RecentItem = {
    id: string;
    type: string;
    type_label: string;
    title: string;
    subtitle: string;
    href: string;
};

export type RecentItemsResponse = {
    items: RecentItem[];
};

export function shouldShowRecentItems(query: string): boolean {
    return query.trim() === '';
}

export function shouldRenderRecentGroup(
    query: string,
    items: readonly RecentItem[],
): boolean {
    return shouldShowRecentItems(query) && items.length > 0;
}

export function recentItemHeading(item: RecentItem): string {
    return `${item.type_label} · ${item.title}`;
}

export function recentItemCommandValue(item: RecentItem): string {
    return `recent ${item.type_label} ${item.title} ${item.subtitle} ${item.id}`;
}

export function recentItemsFromPayload(payload: unknown): RecentItem[] {
    if (typeof payload !== 'object' || payload === null) {
        return [];
    }

    const items = (payload as { items?: unknown }).items;

    if (!Array.isArray(items)) {
        return [];
    }

    return items.flatMap((candidate) => {
        if (typeof candidate !== 'object' || candidate === null) {
            return [];
        }

        const item = candidate as Partial<RecentItem>;

        if (
            typeof item.id !== 'string' ||
            typeof item.type !== 'string' ||
            typeof item.type_label !== 'string' ||
            typeof item.title !== 'string' ||
            typeof item.subtitle !== 'string' ||
            typeof item.href !== 'string' ||
            !item.href.startsWith('/')
        ) {
            return [];
        }

        return [
            {
                id: item.id,
                type: item.type,
                type_label: item.type_label,
                title: item.title,
                subtitle: item.subtitle,
                href: item.href,
            },
        ];
    });
}
