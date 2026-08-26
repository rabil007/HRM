export const SMART_SEARCH_FILTER_KEYS = [
    'status',
    'department_id',
    'position_id',
    'nationality_id',
    'rank_id',
    'crew_status',
    'emirates_id_presence',
] as const;

export const SMART_SEARCH_DEBOUNCE_MS = 450;

export const SMART_SEARCH_MIN_PROMPT_LENGTH = 2;

export const SMART_SEARCH_CACHE_LIMIT = 20;

export type SmartSearchFilterKey = (typeof SMART_SEARCH_FILTER_KEYS)[number];

export type SmartSearchFilters = Partial<Record<SmartSearchFilterKey, string>>;

export type SmartSearchUnresolved = {
    field: string;
    term: string;
    reason: string;
};

export type SmartSearchResponse = {
    filters: Record<string, string>;
    labels: Record<string, string>;
    unresolved: SmartSearchUnresolved[];
    unsupported: string[];
};

export type NormalizedSmartSearchResult = {
    filters: SmartSearchFilters;
    labels: Record<string, string>;
    unresolved: SmartSearchUnresolved[];
    unsupported: string[];
};

export type SmartSearchPreviewChip = {
    key: string;
    title: string;
    label: string;
};

export const SMART_SEARCH_LABEL_TITLES: Record<string, string> = {
    status: 'Status',
    department: 'Department',
    position: 'Position',
    nationality: 'Nationality',
    rank: 'Rank',
    crew_status: 'Crew status',
    emirates_id_presence: 'Emirates ID',
};

const FILTER_TO_LABEL_KEY: Record<SmartSearchFilterKey, string> = {
    status: 'status',
    department_id: 'department',
    position_id: 'position',
    nationality_id: 'nationality',
    rank_id: 'rank',
    crew_status: 'crew_status',
    emirates_id_presence: 'emirates_id_presence',
};

export function buildEmployeeSmartSearchRequestBody(prompt: string): {
    prompt: string;
} {
    return { prompt };
}

export function pickAllowlistedSmartSearchFilters(
    filters: Record<string, unknown>,
): SmartSearchFilters {
    const picked: SmartSearchFilters = {};

    for (const key of SMART_SEARCH_FILTER_KEYS) {
        const value = filters[key];

        if (typeof value === 'string' && value.trim() !== '') {
            picked[key] = value;
        }
    }

    return picked;
}

function hasResolvedSmartSearchFilter(
    filters: SmartSearchFilters,
    key: SmartSearchFilterKey,
): boolean {
    const value = filters[key];

    return typeof value === 'string' && value.trim() !== '';
}

export function mergeSmartSearchFilters<T extends Record<string, string>>(
    currentFilters: T,
    smartSearchFilters: SmartSearchFilters,
): T {
    const merged = {
        ...currentFilters,
        ...smartSearchFilters,
    };
    const resolvedDepartment = hasResolvedSmartSearchFilter(
        smartSearchFilters,
        'department_id',
    );
    const resolvedPosition = hasResolvedSmartSearchFilter(
        smartSearchFilters,
        'position_id',
    );

    if (resolvedDepartment && !resolvedPosition) {
        return {
            ...merged,
            position_id: '',
        };
    }

    if (resolvedPosition && !resolvedDepartment) {
        return {
            ...merged,
            department_id: '',
        };
    }

    return merged;
}

export function clearMatchingOwnedSmartSearchFilters<
    T extends Record<string, string>,
>(currentFilters: T, previousOwned: SmartSearchFilters): T {
    const next = { ...currentFilters };

    for (const key of SMART_SEARCH_FILTER_KEYS) {
        const ownedValue = previousOwned[key];

        if (
            typeof ownedValue === 'string' &&
            ownedValue.trim() !== '' &&
            next[key] === ownedValue
        ) {
            (next as Record<string, string>)[key] = '';
        }
    }

    return next;
}

export function replaceSmartSearchOwnedFilters<
    T extends Record<string, string>,
>(
    currentFilters: T,
    previousOwned: SmartSearchFilters,
    nextSmartSearchFilters: SmartSearchFilters,
): { filters: T; owned: SmartSearchFilters } {
    return {
        filters: mergeSmartSearchFilters(
            clearMatchingOwnedSmartSearchFilters(currentFilters, previousOwned),
            nextSmartSearchFilters,
        ),
        owned: { ...nextSmartSearchFilters },
    };
}

export function employeeDirectoryFiltersEqual<T extends Record<string, string>>(
    left: T,
    right: T,
): boolean {
    const keys = new Set([...Object.keys(left), ...Object.keys(right)]);

    for (const key of keys) {
        if ((left[key] ?? '') !== (right[key] ?? '')) {
            return false;
        }
    }

    return true;
}

export function normalizeSmartSearchPrompt(prompt: string): string {
    return prompt.trim().replace(/\s+/g, ' ');
}

export function isSmartSearchPromptReady(prompt: string): boolean {
    return (
        normalizeSmartSearchPrompt(prompt).length >=
        SMART_SEARCH_MIN_PROMPT_LENGTH
    );
}

export class SmartSearchInterpretationCache {
    private readonly entries = new Map<string, NormalizedSmartSearchResult>();

    private readonly maxSize: number;

    constructor(maxSize = SMART_SEARCH_CACHE_LIMIT) {
        this.maxSize = maxSize;
    }

    get(prompt: string): NormalizedSmartSearchResult | undefined {
        const key = normalizeSmartSearchPrompt(prompt);
        const value = this.entries.get(key);

        if (value === undefined) {
            return undefined;
        }

        this.entries.delete(key);
        this.entries.set(key, value);

        return value;
    }

    set(prompt: string, value: NormalizedSmartSearchResult): void {
        const key = normalizeSmartSearchPrompt(prompt);

        this.entries.delete(key);
        this.entries.set(key, value);

        while (this.entries.size > this.maxSize) {
            const oldest = this.entries.keys().next().value;

            if (oldest === undefined) {
                break;
            }

            this.entries.delete(oldest);
        }
    }

    get size(): number {
        return this.entries.size;
    }
}

export function hasApplyableSmartSearchFilters(
    filters: SmartSearchFilters,
): boolean {
    return SMART_SEARCH_FILTER_KEYS.some((key) => {
        const value = filters[key];

        return typeof value === 'string' && value.trim() !== '';
    });
}

export function smartSearchResolvedPreview(
    filters: SmartSearchFilters,
    labels: Record<string, string>,
): SmartSearchPreviewChip[] {
    const chips: SmartSearchPreviewChip[] = [];

    for (const key of SMART_SEARCH_FILTER_KEYS) {
        if (!filters[key]) {
            continue;
        }

        const labelKey = FILTER_TO_LABEL_KEY[key];
        const label = labels[labelKey];

        if (typeof label !== 'string' || label.trim() === '') {
            continue;
        }

        chips.push({
            key: labelKey,
            title: SMART_SEARCH_LABEL_TITLES[labelKey] ?? labelKey,
            label: label.trim(),
        });
    }

    return chips;
}

export function unresolvedFieldTitle(field: string): string {
    return SMART_SEARCH_LABEL_TITLES[field] ?? 'Value';
}

export function unresolvedReasonMessage(reason: string): string {
    if (reason === 'not_found') {
        return 'No matching value found.';
    }

    if (reason === 'ambiguous') {
        return 'Multiple matches found.';
    }

    return 'Could not resolve this value.';
}

export function formatUnresolvedItem(item: SmartSearchUnresolved): string {
    return `${unresolvedFieldTitle(item.field)} "${item.term}" — ${unresolvedReasonMessage(item.reason)}`;
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function pickStringRecord(value: unknown): Record<string, string> {
    if (!isPlainObject(value)) {
        return {};
    }

    const record: Record<string, string> = {};

    for (const [key, entry] of Object.entries(value)) {
        if (typeof entry === 'string' && entry.trim() !== '') {
            record[key] = entry;
        }
    }

    return record;
}

function normalizeUnresolved(value: unknown): SmartSearchUnresolved[] {
    if (!Array.isArray(value)) {
        return [];
    }

    const unresolved: SmartSearchUnresolved[] = [];

    for (const item of value) {
        if (!item || typeof item !== 'object') {
            continue;
        }

        const record = item as Record<string, unknown>;
        const field =
            typeof record.field === 'string' ? record.field.trim() : '';
        const term = typeof record.term === 'string' ? record.term.trim() : '';
        const reason =
            typeof record.reason === 'string' ? record.reason.trim() : '';

        if (field === '' || term === '' || reason === '') {
            continue;
        }

        unresolved.push({ field, term, reason });
    }

    return unresolved;
}

function normalizeUnsupported(value: unknown): string[] {
    if (!Array.isArray(value)) {
        return [];
    }

    const unsupported: string[] = [];

    for (const item of value) {
        if (typeof item !== 'string') {
            continue;
        }

        const term = item.trim();

        if (term !== '') {
            unsupported.push(term);
        }
    }

    return unsupported;
}

export function normalizeSmartSearchResponse(
    payload: unknown,
): NormalizedSmartSearchResult {
    if (!payload || typeof payload !== 'object') {
        return {
            filters: {},
            labels: {},
            unresolved: [],
            unsupported: [],
        };
    }

    const data = payload as Record<string, unknown>;

    return {
        filters: isPlainObject(data.filters)
            ? pickAllowlistedSmartSearchFilters(data.filters)
            : {},
        labels: pickStringRecord(data.labels),
        unresolved: normalizeUnresolved(data.unresolved),
        unsupported: normalizeUnsupported(data.unsupported),
    };
}

function firstValidationMessage(payload: unknown): string | null {
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    const data = payload as {
        message?: unknown;
        errors?: Record<string, string[] | string>;
    };

    if (typeof data.message === 'string' && data.message.trim() !== '') {
        return data.message.trim();
    }

    const firstError = Object.values(data.errors ?? {})[0];

    if (Array.isArray(firstError) && typeof firstError[0] === 'string') {
        return firstError[0];
    }

    if (typeof firstError === 'string' && firstError.trim() !== '') {
        return firstError;
    }

    return null;
}

export function smartSearchErrorMessage(
    status: number,
    payload?: unknown,
): string {
    if (status === 403) {
        return 'Smart Employee Search is currently disabled.';
    }

    if (status === 429) {
        return 'Too many Smart Search requests. Try again shortly.';
    }

    if (status === 503) {
        return 'Smart Search is temporarily unavailable.';
    }

    if (status === 422) {
        return (
            firstValidationMessage(payload) ??
            "Smart Search couldn't update the results. Try again."
        );
    }

    return "Smart Search couldn't update the results. Try again.";
}
