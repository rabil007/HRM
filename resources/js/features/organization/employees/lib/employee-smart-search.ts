export const SMART_SEARCH_FILTER_KEYS = [
    'status',
    'branch_id',
    'department_id',
    'position_id',
    'nationality_id',
    'rank_id',
    'gender_id',
    'visa_type_id',
    'company_visa_type_id',
    'role_id',
    'approval_location_id',
    'sssa_option_id',
    'crew_status',
    'missing_fields',
    'present_fields',
] as const;

export const SMART_SEARCH_DEBOUNCE_MS = 450;

export const SMART_SEARCH_MIN_PROMPT_LENGTH = 2;

export const SMART_SEARCH_CACHE_LIMIT = 20;

export const EMPLOYEE_DIRECTORY_PARTIAL_RELOAD_KEYS = [
    'employees',
    'pagination',
    'search',
    'filters',
    'department_tree',
    'department_tree_selected_id',
    'department_tree_selected_position_id',
] as const;

export type SmartSearchFilterKey = (typeof SMART_SEARCH_FILTER_KEYS)[number];

export type SmartSearchFilters = Partial<Record<SmartSearchFilterKey, string>>;

export type SmartSearchUnresolved = {
    field: string;
    term: string;
    reason: string;
};

export type SmartSearchApplied = {
    key: string;
    label: string;
    value: string;
};

export type SmartSearchResponse = {
    filters: Record<string, string>;
    applied: SmartSearchApplied[];
    unresolved: SmartSearchUnresolved[];
    ambiguous: SmartSearchUnresolved[];
    unsupported: string[];
};

export type NormalizedSmartSearchResult = {
    filters: SmartSearchFilters;
    applied: SmartSearchApplied[];
    unresolved: SmartSearchUnresolved[];
    ambiguous: SmartSearchUnresolved[];
    unsupported: string[];
};

export type SmartSearchPreviewChip = {
    key: string;
    title: string;
    label: string;
};

export const COMPLETENESS_LABELS: Record<string, string> = {
    branch: 'Branch',
    department: 'Department',
    position: 'Position',
    nationality: 'Nationality',
    rank: 'Rank',
    gender: 'Gender',
    visa_type: 'Visa type',
    sponsor: 'Sponsor',
    emirates_id: 'Emirates ID',
    passport_number: 'Passport',
    work_email: 'Work email',
    personal_email: 'Personal email',
    email: 'Email',
    phone: 'Phone',
    phone_home_country: 'Home country phone',
    date_of_birth: 'Date of birth',
    hire_date: 'Hire date',
    nearest_airport: 'Nearest airport',
    emergency_contact: 'Emergency contact',
    emergency_phone: 'Emergency phone',
    place_of_birth: 'Place of birth',
};

export const SMART_SEARCH_LABEL_TITLES: Record<string, string> = {
    status: 'HR status',
    branch: 'Branch',
    department: 'Department',
    position: 'Position',
    nationality: 'Nationality',
    rank: 'Rank',
    gender: 'Gender',
    visa_type: 'Visa type',
    sponsor: 'Sponsor',
    role: 'Role',
    approval_location: 'Approval location',
    sssa_option: 'SSSA option',
    crew_status: 'Crew status',
    ...COMPLETENESS_LABELS,
};

export const STATUS_OPTION_LABELS: Record<string, string> = {
    '': 'Active (default)',
    all: 'All statuses',
    active: 'Active',
    inactive: 'Inactive',
    on_leave: 'On leave',
    terminated: 'Terminated',
};

export function buildEmployeeSmartSearchRequestBody(prompt: string): {
    prompt: string;
} {
    return { prompt };
}

export function parseCompletenessCsv(value: string | undefined): string[] {
    if (typeof value !== 'string' || value.trim() === '') {
        return [];
    }

    const allowed = Object.keys(COMPLETENESS_LABELS);
    const unique = new Set(
        value
            .split(',')
            .map((item) => item.trim())
            .filter((item) => item !== '' && allowed.includes(item)),
    );

    return allowed.filter((key) => unique.has(key));
}

export function completenessCsv(keys: string[]): string {
    return parseCompletenessCsv(keys.join(',')).join(',');
}

export function emiratesIdPresenceValue(filters: {
    missing_fields?: string;
    present_fields?: string;
}): string {
    const missing = new Set(parseCompletenessCsv(filters.missing_fields));
    const present = new Set(parseCompletenessCsv(filters.present_fields));

    if (missing.has('emirates_id')) {
        return 'missing';
    }

    if (present.has('emirates_id')) {
        return 'present';
    }

    return '';
}

export function applyEmiratesIdPresence<
    T extends { missing_fields: string; present_fields: string },
>(filters: T, value: string): T {
    const missing = new Set(parseCompletenessCsv(filters.missing_fields));
    const present = new Set(parseCompletenessCsv(filters.present_fields));

    missing.delete('emirates_id');
    present.delete('emirates_id');

    if (value === 'missing') {
        missing.add('emirates_id');
    }

    if (value === 'present') {
        present.add('emirates_id');
    }

    return {
        ...filters,
        missing_fields: completenessCsv([...missing]),
        present_fields: completenessCsv([...present]),
    };
}

export function removeCompletenessKey<
    T extends { missing_fields: string; present_fields: string },
>(filters: T, operator: 'missing' | 'present', concept: string): T {
    const field = operator === 'missing' ? 'missing_fields' : 'present_fields';
    const next = parseCompletenessCsv(filters[field]).filter(
        (key) => key !== concept,
    );

    return {
        ...filters,
        [field]: completenessCsv(next),
    };
}

export function completenessChips(filters: {
    missing_fields?: string;
    present_fields?: string;
}): SmartSearchPreviewChip[] {
    const chips: SmartSearchPreviewChip[] = [];

    for (const key of parseCompletenessCsv(filters.missing_fields)) {
        chips.push({
            key: `${key}:missing`,
            title: COMPLETENESS_LABELS[key] ?? key,
            label: 'Missing',
        });
    }

    for (const key of parseCompletenessCsv(filters.present_fields)) {
        chips.push({
            key: `${key}:present`,
            title: COMPLETENESS_LABELS[key] ?? key,
            label: 'Present',
        });
    }

    return chips;
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

export function reconcileSmartSearchOwnership<T extends Record<string, string>>(
    currentFilters: T,
    owned: SmartSearchFilters,
): SmartSearchFilters {
    const next: SmartSearchFilters = {};

    for (const key of SMART_SEARCH_FILTER_KEYS) {
        const ownedValue = owned[key];

        if (typeof ownedValue !== 'string' || ownedValue.trim() === '') {
            continue;
        }

        if (key === 'missing_fields' || key === 'present_fields') {
            const remaining = completenessCsv(
                parseCompletenessCsv(currentFilters[key]).filter((item) =>
                    parseCompletenessCsv(ownedValue).includes(item),
                ),
            );

            if (remaining !== '') {
                next[key] = remaining;
            }

            continue;
        }

        if (currentFilters[key] === ownedValue) {
            next[key] = ownedValue;
        }
    }

    return next;
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

export function smartSearchCacheKey(prompt: string): string {
    return normalizeSmartSearchPrompt(prompt).toLowerCase();
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
        const key = smartSearchCacheKey(prompt);
        const value = this.entries.get(key);

        if (value === undefined) {
            return undefined;
        }

        this.entries.delete(key);
        this.entries.set(key, value);

        return value;
    }

    set(prompt: string, value: NormalizedSmartSearchResult): void {
        const key = smartSearchCacheKey(prompt);

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

export function hasActiveSmartSearchOwnedFilters(
    owned: SmartSearchFilters,
): boolean {
    return hasApplyableSmartSearchFilters(owned);
}

export function employeeDirectoryEmptyStateTitle(
    hasActiveSmartSearchFilters: boolean,
): string {
    return hasActiveSmartSearchFilters
        ? 'No employees match the Smart Search and current directory filters.'
        : 'No employees found.';
}

export const SMART_SEARCH_OVERRIDDEN_COPY =
    'Smart Search filters are no longer active because the directory filters were changed.';

export type SmartSearchResultCopyKind = 'applied' | 'unchanged' | 'overridden';

export function smartSearchResultCopyKind({
    result,
    previewChips,
}: {
    result: NormalizedSmartSearchResult | null;
    previewChips: SmartSearchPreviewChip[];
}): SmartSearchResultCopyKind | null {
    if (result === null) {
        return null;
    }

    if (previewChips.length > 0) {
        return 'applied';
    }

    if (
        hasApplyableSmartSearchFilters(result.filters) ||
        result.applied.length > 0
    ) {
        return 'overridden';
    }

    return 'unchanged';
}

export function employeeActiveFilterCount(
    filters: Record<string, string>,
): number {
    let count = 0;

    for (const [key, value] of Object.entries(filters)) {
        if (typeof value !== 'string' || value.trim() === '') {
            continue;
        }

        if (key === 'missing_fields' || key === 'present_fields') {
            count += parseCompletenessCsv(value).length;

            continue;
        }

        count += 1;
    }

    return count;
}

export function smartSearchResolvedPreview(
    applied: SmartSearchApplied[],
    owned: SmartSearchFilters = {},
    currentFilters: Record<string, string> = {},
): SmartSearchPreviewChip[] {
    const reconciled = reconcileSmartSearchOwnership(currentFilters, owned);
    const ownedKeys = new Set(Object.keys(reconciled));
    const restrictOwnership =
        ownedKeys.size > 0 || Object.keys(owned).length > 0;

    return applied.flatMap((item) => {
        if (restrictOwnership && !appliedItemIsOwned(item, reconciled)) {
            return [];
        }

        const title = item.label.trim();
        const label = item.value.trim();

        if (title === '' || label === '') {
            return [];
        }

        return [
            {
                key: item.key,
                title,
                label,
            },
        ];
    });
}

function appliedItemIsOwned(
    item: SmartSearchApplied,
    owned: SmartSearchFilters,
): boolean {
    const [concept, operator] = item.key.split(':');

    if (operator === 'missing') {
        return parseCompletenessCsv(owned.missing_fields).includes(concept);
    }

    if (operator === 'present') {
        return parseCompletenessCsv(owned.present_fields).includes(concept);
    }

    const filterKey = appliedKeyToFilterKey(item.key);

    return filterKey !== null && owned[filterKey] !== undefined;
}

export function directoryScopeChips(filters: {
    status?: string;
}): SmartSearchPreviewChip[] {
    if ((filters.status ?? '') !== '') {
        return [];
    }

    return [
        {
            key: 'status:default',
            title: 'HR status',
            label: STATUS_OPTION_LABELS[''],
        },
    ];
}

export function unresolvedFieldTitle(field: string): string {
    return SMART_SEARCH_LABEL_TITLES[field] ?? 'Value';
}

export function unresolvedReasonMessage(reason: string): string {
    if (reason === 'not_found') {
        return 'No matching value found.';
    }

    if (reason === 'ambiguous' || reason === 'multiple_values') {
        return 'Multiple matches found.';
    }

    if (reason === 'conflict' || reason === 'needs_clarification') {
        return 'Needs clarification.';
    }

    return 'Could not resolve this value.';
}

export function formatUnresolvedItem(item: SmartSearchUnresolved): string {
    return `${unresolvedFieldTitle(item.field)} "${item.term}" — ${unresolvedReasonMessage(item.reason)}`;
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function parseApplied(value: unknown): SmartSearchApplied[] | null {
    if (!Array.isArray(value)) {
        return null;
    }

    const applied: SmartSearchApplied[] = [];

    for (const item of value) {
        if (!isPlainObject(item)) {
            return null;
        }

        const key = typeof item.key === 'string' ? item.key.trim() : '';
        const label = typeof item.label === 'string' ? item.label.trim() : '';
        const displayValue =
            typeof item.value === 'string' ? item.value.trim() : '';

        if (key === '' || label === '' || displayValue === '') {
            return null;
        }

        applied.push({ key, label, value: displayValue });
    }

    return applied;
}

function parseUnresolved(value: unknown): SmartSearchUnresolved[] | null {
    if (!Array.isArray(value)) {
        return null;
    }

    const unresolved: SmartSearchUnresolved[] = [];

    for (const item of value) {
        if (!isPlainObject(item)) {
            return null;
        }

        const field = typeof item.field === 'string' ? item.field.trim() : '';
        const term = typeof item.term === 'string' ? item.term.trim() : '';
        const reason =
            typeof item.reason === 'string' ? item.reason.trim() : '';

        if (field === '' || term === '' || reason === '') {
            return null;
        }

        unresolved.push({ field, term, reason });
    }

    return unresolved;
}

function parseUnsupported(value: unknown): string[] | null {
    if (!Array.isArray(value)) {
        return null;
    }

    const unsupported: string[] = [];

    for (const item of value) {
        if (typeof item !== 'string') {
            return null;
        }

        const term = item.trim();

        if (term !== '') {
            unsupported.push(term);
        }
    }

    return unsupported;
}

function appliedKeyToFilterKey(key: string): SmartSearchFilterKey | null {
    const [concept, operator] = key.split(':');

    if (operator === 'missing') {
        return 'missing_fields';
    }

    if (operator === 'present') {
        return 'present_fields';
    }

    const mapped: Record<string, SmartSearchFilterKey> = {
        status: 'status',
        branch: 'branch_id',
        department: 'department_id',
        position: 'position_id',
        nationality: 'nationality_id',
        rank: 'rank_id',
        gender: 'gender_id',
        visa_type: 'visa_type_id',
        sponsor: 'company_visa_type_id',
        role: 'role_id',
        approval_location: 'approval_location_id',
        sssa_option: 'sssa_option_id',
        crew_status: 'crew_status',
    };

    return mapped[concept] ?? null;
}

function filtersCoveredByApplied(
    filters: SmartSearchFilters,
    applied: SmartSearchApplied[],
): SmartSearchFilters {
    const covered: SmartSearchFilters = {};
    const appliedFilterKeys = new Set(
        applied
            .map((item) => appliedKeyToFilterKey(item.key))
            .filter((key): key is SmartSearchFilterKey => key !== null),
    );
    const missingConcepts = applied
        .filter((item) => item.key.endsWith(':missing'))
        .map((item) => item.key.split(':')[0]);
    const presentConcepts = applied
        .filter((item) => item.key.endsWith(':present'))
        .map((item) => item.key.split(':')[0]);

    for (const key of SMART_SEARCH_FILTER_KEYS) {
        const value = filters[key];

        if (typeof value !== 'string' || value.trim() === '') {
            continue;
        }

        if (key === 'missing_fields') {
            const csv = completenessCsv(
                parseCompletenessCsv(value).filter((item) =>
                    missingConcepts.includes(item),
                ),
            );

            if (csv !== '') {
                covered[key] = csv;
            }

            continue;
        }

        if (key === 'present_fields') {
            const csv = completenessCsv(
                parseCompletenessCsv(value).filter((item) =>
                    presentConcepts.includes(item),
                ),
            );

            if (csv !== '') {
                covered[key] = csv;
            }

            continue;
        }

        if (appliedFilterKeys.has(key)) {
            covered[key] = value;
        }
    }

    return covered;
}

export class SmartSearchMalformedResponseError extends Error {
    constructor() {
        super('Smart Search is temporarily unavailable.');
        this.name = 'SmartSearchMalformedResponseError';
    }
}

export function parseSmartSearchResponse(
    payload: unknown,
): NormalizedSmartSearchResult {
    if (!isPlainObject(payload) || !isPlainObject(payload.filters)) {
        throw new SmartSearchMalformedResponseError();
    }

    const applied = parseApplied(payload.applied);
    const unresolved = parseUnresolved(payload.unresolved);
    const ambiguous = parseUnresolved(payload.ambiguous);
    const unsupported = parseUnsupported(payload.unsupported);

    if (
        applied === null ||
        unresolved === null ||
        ambiguous === null ||
        unsupported === null
    ) {
        throw new SmartSearchMalformedResponseError();
    }

    return {
        filters: filtersCoveredByApplied(
            pickAllowlistedSmartSearchFilters(payload.filters),
            applied,
        ),
        applied,
        unresolved,
        ambiguous,
        unsupported,
    };
}

export function reconcileServerWorkingFilters<T extends Record<string, string>>(
    working: T,
    server: T,
    pendingApply: boolean,
): { working: T; pendingApply: boolean; adoptServer: boolean } {
    if (employeeDirectoryFiltersEqual(working, server)) {
        return { working: server, pendingApply: false, adoptServer: true };
    }

    if (pendingApply) {
        return { working, pendingApply: true, adoptServer: false };
    }

    return { working: server, pendingApply: false, adoptServer: true };
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

export function parseRetryAfterSeconds(header: string | null): number | null {
    if (header === null || header.trim() === '') {
        return null;
    }

    const asNumber = Number(header);

    if (Number.isFinite(asNumber) && asNumber > 0) {
        return Math.min(Math.ceil(asNumber), 60);
    }

    const asDate = Date.parse(header);

    if (Number.isNaN(asDate)) {
        return null;
    }

    const seconds = Math.ceil((asDate - Date.now()) / 1000);

    if (seconds <= 0) {
        return null;
    }

    return Math.min(seconds, 60);
}
