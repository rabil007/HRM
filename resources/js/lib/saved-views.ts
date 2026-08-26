export const SAVED_VIEW_PAGE_KEYS = [
    'employees',
    'documents',
    'crew',
    'leave',
    'payroll',
] as const;

export type SavedViewPageKey = (typeof SAVED_VIEW_PAGE_KEYS)[number];

export type SavedView = {
    id: number;
    name: string;
    filters: Record<string, string>;
    is_default: boolean;
};

const FILTER_KEYS: Record<SavedViewPageKey, readonly string[]> = {
    employees: [
        'search',
        'status',
        'branch_id',
        'department_id',
        'position_id',
        'manager_id',
        'gender_id',
        'nationality_id',
        'visa_type_id',
        'company_visa_type_id',
        'rank_id',
        'approval_location_id',
        'sssa_option_id',
        'crew_status',
        'role_id',
        'missing_fields',
        'present_fields',
    ],
    documents: ['search', 'expiry', 'requirement_status', 'department_id'],
    crew: [
        'search',
        'phase',
        'status',
        'vessel_id',
        'rank_id',
        'client_id',
        'employee_id',
        'planned_join_from',
        'planned_join_to',
        'planned_signoff_from',
        'planned_signoff_to',
        'tour_status',
        'relief_status',
        'relief_risk',
        'relief_not_ready',
        'signoff_within_14_no_relief',
        'movement_attention',
        'include_completed',
        'view',
    ],
    leave: ['search', 'status', 'employee_id', 'leave_type_id', 'scope'],
    payroll: ['search', 'category', 'status', 'date_from', 'date_to'],
};

const OMITTED_VALUES: Partial<
    Record<SavedViewPageKey, Record<string, readonly string[]>>
> = {
    documents: { expiry: ['all'] },
    leave: { scope: ['my'] },
    crew: { view: ['crew'] },
};

const BOOLEAN_KEYS: Partial<Record<SavedViewPageKey, readonly string[]>> = {
    crew: [
        'relief_not_ready',
        'signoff_within_14_no_relief',
        'movement_attention',
        'include_completed',
    ],
};

const REJECTED_KEYS = new Set([
    'page',
    'per_page',
    'sort',
    'direction',
    'company_id',
    'user_id',
    '_token',
    'csrf',
    'url',
    'href',
    'path',
    'query',
]);

export function isSupportedSavedViewPage(
    pageKey: string,
): pageKey is SavedViewPageKey {
    return (SAVED_VIEW_PAGE_KEYS as readonly string[]).includes(pageKey);
}

export function savedViewFilterKeys(
    pageKey: SavedViewPageKey,
): readonly string[] {
    return FILTER_KEYS[pageKey];
}

export function captureCurrentFilters(
    pageKey: SavedViewPageKey,
    raw: Record<string, unknown>,
): Record<string, string> {
    return normalizeFilterRecord(pageKey, raw);
}

export function applySavedViewFilters(
    pageKey: SavedViewPageKey,
    stored: Record<string, unknown>,
): Record<string, string> {
    return normalizeFilterRecord(pageKey, stored);
}

export function savedViewFiltersMatch(
    left: Record<string, string>,
    right: Record<string, string>,
): boolean {
    const leftKeys = Object.keys(left).sort();
    const rightKeys = Object.keys(right).sort();

    if (leftKeys.length !== rightKeys.length) {
        return false;
    }

    return leftKeys.every(
        (key, index) => key === rightKeys[index] && left[key] === right[key],
    );
}

function normalizeFilterRecord(
    pageKey: SavedViewPageKey,
    raw: Record<string, unknown>,
): Record<string, string> {
    const allowed = new Set(FILTER_KEYS[pageKey]);
    const omitted = OMITTED_VALUES[pageKey] ?? {};
    const booleans = new Set(BOOLEAN_KEYS[pageKey] ?? []);
    const normalized: Record<string, string> = {};

    for (const [key, value] of Object.entries(raw)) {
        if (REJECTED_KEYS.has(key) || !allowed.has(key)) {
            continue;
        }

        if (booleans.has(key)) {
            if (isTruthyFilter(value)) {
                normalized[key] = '1';
            }

            continue;
        }

        if (value === null || value === undefined || value === false) {
            continue;
        }

        const asString = String(value).trim();

        if (asString === '' || (omitted[key] ?? []).includes(asString)) {
            continue;
        }

        normalized[key] = asString;
    }

    return normalized;
}

function isTruthyFilter(value: unknown): boolean {
    return (
        value === true ||
        value === 1 ||
        value === '1' ||
        value === 'true' ||
        value === 'on'
    );
}
