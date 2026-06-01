type SearchableOption = {
    label: string;
    value: string;
    keywords?: string;
    search?: string;
};

export function filterCreatableOptions<T extends SearchableOption>(
    options: T[],
    query: string,
): T[] {
    const normalized = query.trim().toLowerCase();

    if (normalized === '') {
        return options;
    }

    return options.filter((option) => {
        const haystack = [option.search, option.label, option.value, option.keywords]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return haystack.includes(normalized);
    });
}
