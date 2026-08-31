import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    SMART_SEARCH_CACHE_LIMIT,
    SMART_SEARCH_DEBOUNCE_MS,
    SMART_SEARCH_FILTER_KEYS,
    SMART_SEARCH_MIN_PROMPT_LENGTH,
    SMART_SEARCH_OVERRIDDEN_COPY,
    STATUS_OPTION_LABELS,
    SmartSearchInterpretationCache,
    SmartSearchMalformedResponseError,
    applyEmiratesIdPresence,
    buildEmployeeSmartSearchRequestBody,
    completenessChips,
    directoryScopeChips,
    employeeActiveFilterCount,
    employeeDirectoryEmptyStateTitle,
    employeeDirectoryFiltersEqual,
    formatUnresolvedItem,
    hasActiveSmartSearchOwnedFilters,
    hasApplyableSmartSearchFilters,
    isSmartSearchPromptReady,
    mergeSmartSearchFilters,
    normalizeSmartSearchPrompt,
    parseRetryAfterSeconds,
    parseSmartSearchResponse,
    pickAllowlistedSmartSearchFilters,
    reconcileServerWorkingFilters,
    reconcileSmartSearchOwnership,
    replaceSmartSearchOwnedFilters,
    smartSearchCacheKey,
    smartSearchErrorMessage,
    smartSearchFiltersEqual,
    smartSearchResolvedPreview,
    smartSearchResultCopyKind,
} from './employee-smart-search.ts';

const emptyFilters = {
    branch_id: '',
    department_id: '',
    position_id: '',
    status: '',
    manager_id: '',
    gender_id: '',
    nationality_id: '',
    visa_type_id: '',
    company_visa_type_id: '',
    rank_id: '',
    project_id: '',
    approval_location_id: '',
    sssa_option_id: '',
    crew_status: '',
    role_id: '',
    missing_fields: '',
    present_fields: '',
};

function parsed(payload: Record<string, unknown>) {
    return parseSmartSearchResponse(payload);
}

describe('employee smart search request body', () => {
    it('contains only the prompt', () => {
        const body = buildEmployeeSmartSearchRequestBody(
            'active Filipino AB crew in Crewing',
        );

        assert.deepEqual(Object.keys(body), ['prompt']);
        assert.equal(body.prompt, 'active Filipino AB crew in Crewing');
        assert.equal(
            JSON.stringify(body),
            '{"prompt":"active Filipino AB crew in Crewing"}',
        );
        assert.equal('company_id' in body, false);
        assert.equal('filters' in body, false);
        assert.equal('provider' in body, false);
        assert.equal('model' in body, false);
    });
});

describe('employee smart search constants', () => {
    it('keeps auto-search timing and minimum prompt length', () => {
        assert.equal(SMART_SEARCH_DEBOUNCE_MS, 450);
        assert.equal(SMART_SEARCH_MIN_PROMPT_LENGTH, 2);
        assert.equal(isSmartSearchPromptReady('AB'), true);
        assert.equal(isSmartSearchPromptReady('A'), false);
        assert.equal(isSmartSearchPromptReady(''), false);
        assert.equal(isSmartSearchPromptReady('  '), false);
    });
});

describe('employee smart search ownership equality', () => {
    it('treats equivalent ownership as unchanged', () => {
        assert.equal(smartSearchFiltersEqual({}, {}), true);
        assert.equal(
            smartSearchFiltersEqual(
                { status: 'active', nationality_id: '5' },
                { nationality_id: '5', status: 'active' },
            ),
            true,
        );
    });

    it('detects ownership value changes', () => {
        assert.equal(
            smartSearchFiltersEqual(
                { status: 'active' },
                { status: 'inactive' },
            ),
            false,
        );
        assert.equal(smartSearchFiltersEqual({ status: 'active' }, {}), false);
    });
});

describe('employee smart search filter allowlist', () => {
    it('includes directory and completeness keys only', () => {
        assert.deepEqual(SMART_SEARCH_FILTER_KEYS, [
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
        ]);
    });

    it('ignores arbitrary unexpected response filter keys', () => {
        const picked = pickAllowlistedSmartSearchFilters({
            status: 'active',
            nationality_id: '5',
            manager_id: '10',
            company_id: '99',
            search: 'secret employee',
            salary: '9000',
            emirates_id: '784-1234-1234567-1',
        });

        assert.deepEqual(picked, {
            status: 'active',
            nationality_id: '5',
        });
        assert.equal('manager_id' in picked, false);
        assert.equal('company_id' in picked, false);
        assert.equal('emirates_id' in picked, false);
    });
});

describe('employee smart search filter merge', () => {
    it('overwrites returned supported fields and preserves unrelated filters', () => {
        const merged = mergeSmartSearchFilters(
            {
                ...emptyFilters,
                status: 'inactive',
                manager_id: '10',
                gender_id: '2',
                visa_type_id: '7',
                role_id: '3',
            },
            {
                status: 'active',
                nationality_id: '5',
            },
        );

        assert.equal(merged.manager_id, '10');
        assert.equal(merged.gender_id, '2');
        assert.equal(merged.visa_type_id, '7');
        assert.equal(merged.role_id, '3');
        assert.equal(merged.status, 'active');
        assert.equal(merged.nationality_id, '5');
    });

    it('clears a stale position when Smart Search returns only department', () => {
        const merged = mergeSmartSearchFilters(
            {
                ...emptyFilters,
                department_id: '10',
                position_id: '21',
                manager_id: '9',
            },
            {
                department_id: '15',
                status: 'active',
            },
        );

        assert.equal(merged.department_id, '15');
        assert.equal(merged.position_id, '');
        assert.equal(merged.manager_id, '9');
    });

    it('clears a stale department when Smart Search returns only position', () => {
        const merged = mergeSmartSearchFilters(
            { ...emptyFilters, department_id: '10' },
            { position_id: '31' },
        );

        assert.equal(merged.department_id, '');
        assert.equal(merged.position_id, '31');
    });

    it('applies both department and position when Smart Search returns both', () => {
        const merged = mergeSmartSearchFilters(
            { ...emptyFilters, department_id: '10', position_id: '21' },
            { department_id: '15', position_id: '44' },
        );

        assert.equal(merged.department_id, '15');
        assert.equal(merged.position_id, '44');
    });

    it('preserves existing department and position when neither is returned', () => {
        const merged = mergeSmartSearchFilters(
            { ...emptyFilters, department_id: '10', position_id: '21' },
            { nationality_id: '5' },
        );

        assert.equal(merged.department_id, '10');
        assert.equal(merged.position_id, '21');
        assert.equal(merged.nationality_id, '5');
    });
});

describe('employee smart search owned filter replacement', () => {
    it('removes a previous AI-owned filter omitted by a new result', () => {
        const { filters, owned } = replaceSmartSearchOwnedFilters(
            {
                ...emptyFilters,
                status: 'active',
                nationality_id: '5',
                rank_id: '8',
                manager_id: '10',
            },
            {
                status: 'active',
                nationality_id: '5',
                rank_id: '8',
            },
            {
                status: 'active',
                nationality_id: '5',
            },
        );

        assert.equal(filters.rank_id, '');
        assert.equal(filters.manager_id, '10');
        assert.equal('rank_id' in owned, false);
    });

    it('preserves a manually overridden previous AI filter', () => {
        const { filters } = replaceSmartSearchOwnedFilters(
            { ...emptyFilters, rank_id: '44', status: 'active' },
            { rank_id: '8', status: 'active' },
            { status: 'active' },
        );

        assert.equal(filters.rank_id, '44');
        assert.equal(filters.status, 'active');
    });

    it('removes a stale Smart Search chip after a manual override', () => {
        const owned = reconcileSmartSearchOwnership(
            { ...emptyFilters, rank_id: '44' },
            { rank_id: '8' },
        );

        assert.deepEqual(owned, {});
        assert.deepEqual(
            smartSearchResolvedPreview(
                [{ key: 'rank:equals', label: 'Rank', value: 'AB' }],
                { rank_id: '8' },
                { ...emptyFilters, rank_id: '44' },
            ),
            [],
        );
    });

    it('clears still-AI-owned filters when Smart Search is cleared', () => {
        const { filters, owned } = replaceSmartSearchOwnedFilters(
            {
                ...emptyFilters,
                status: 'active',
                nationality_id: '5',
                manager_id: '10',
            },
            { status: 'active', nationality_id: '5' },
            {},
        );

        assert.equal(filters.status, '');
        assert.equal(filters.nationality_id, '');
        assert.equal(filters.manager_id, '10');
        assert.deepEqual(owned, {});
    });

    it('editing AB down to A removes still-owned AB filters', () => {
        const { filters } = replaceSmartSearchOwnedFilters(
            { ...emptyFilters, rank_id: '8', manager_id: '10' },
            { rank_id: '8' },
            {},
        );

        assert.equal(filters.rank_id, '');
        assert.equal(filters.manager_id, '10');
    });
});

describe('employee smart search inertia race', () => {
    it('computes query B from the working snapshot, not stale props', () => {
        let working = { ...emptyFilters };
        let owned = {};

        const appliedA = replaceSmartSearchOwnedFilters(working, owned, {
            status: 'active',
            nationality_id: 'ph',
            rank_id: 'ab',
        });
        working = appliedA.filters;
        owned = appliedA.owned;

        const staleServer = { ...emptyFilters };
        const race = reconcileServerWorkingFilters(working, staleServer, true);

        assert.equal(race.adoptServer, false);
        assert.equal(race.working.nationality_id, 'ph');

        const appliedB = replaceSmartSearchOwnedFilters(race.working, owned, {
            status: 'active',
            nationality_id: 'in',
        });

        assert.equal(appliedB.filters.nationality_id, 'in');
        assert.equal(appliedB.filters.rank_id, '');
        assert.equal(appliedB.filters.status, 'active');
        assert.equal('rank_id' in appliedB.owned, false);
    });

    it('adopts server props when no Smart Search apply is pending', () => {
        const result = reconcileServerWorkingFilters(
            { ...emptyFilters, nationality_id: 'ph' },
            { ...emptyFilters, gender_id: '2' },
            false,
        );

        assert.equal(result.adoptServer, true);
        assert.equal(result.working.gender_id, '2');
        assert.equal(result.working.nationality_id, '');
    });

    it('keeps a manual change made while a Smart Search apply is in flight', () => {
        const working = {
            ...emptyFilters,
            nationality_id: 'in',
            gender_id: '2',
        };
        const staleServer = { ...emptyFilters, nationality_id: 'ph' };
        const result = reconcileServerWorkingFilters(
            working,
            staleServer,
            true,
        );

        assert.equal(result.adoptServer, false);
        assert.equal(result.working.gender_id, '2');
        assert.equal(result.working.nationality_id, 'in');
    });
});

describe('employee smart search status labels', () => {
    it('does not label blank status as All', () => {
        assert.equal(STATUS_OPTION_LABELS[''], 'Active (default)');
        assert.equal(STATUS_OPTION_LABELS.all, 'All statuses');
        assert.equal(STATUS_OPTION_LABELS.active, 'Active');
        assert.notEqual(STATUS_OPTION_LABELS[''], 'All');
    });

    it('shows directory default scope only when status is blank', () => {
        assert.deepEqual(directoryScopeChips({ status: '' }), [
            {
                key: 'status:default',
                title: 'HR status',
                label: 'Active (default)',
            },
        ]);
        assert.deepEqual(directoryScopeChips({ status: 'all' }), []);
        assert.deepEqual(directoryScopeChips({ status: 'active' }), []);
    });
});

describe('employee smart search completeness', () => {
    it('displays and removes email missing without splitting work and personal', () => {
        const chips = completenessChips({ missing_fields: 'email' });

        assert.deepEqual(
            chips.map((chip) => `${chip.label} · ${chip.title}`),
            ['Missing · Email'],
        );
    });

    it('displays work email, personal email, phone, dob, nationality, passport, and emirates id', () => {
        const chips = completenessChips({
            missing_fields:
                'work_email,personal_email,phone,date_of_birth,nationality,emirates_id',
            present_fields: 'passport_number',
        });

        assert.deepEqual(
            chips.map((chip) => `${chip.label} · ${chip.title}`),
            [
                'Missing · Nationality',
                'Missing · Emirates ID',
                'Missing · Work email',
                'Missing · Personal email',
                'Missing · Phone',
                'Missing · Date of birth',
                'Present · Passport',
            ],
        );
    });

    it('maps the Emirates ID convenience control onto generic completeness', () => {
        const missing = applyEmiratesIdPresence(emptyFilters, 'missing');
        const present = applyEmiratesIdPresence(missing, 'present');

        assert.equal(missing.missing_fields, 'emirates_id');
        assert.equal(present.missing_fields, '');
        assert.equal(present.present_fields, 'emirates_id');
    });
});

describe('employee smart search preview', () => {
    it('uses applied labels rather than numeric IDs', () => {
        const chips = smartSearchResolvedPreview([
            { key: 'status:equals', label: 'HR status', value: 'Active' },
            { key: 'department:equals', label: 'Department', value: 'Crewing' },
            {
                key: 'nationality:equals',
                label: 'Nationality',
                value: 'Philippines',
            },
            { key: 'rank:equals', label: 'Rank', value: 'AB' },
        ]);

        assert.deepEqual(
            chips.map((chip) => `${chip.title} · ${chip.label}`),
            [
                'HR status · Active',
                'Department · Crewing',
                'Nationality · Philippines',
                'Rank · AB',
            ],
        );
        assert.equal(
            chips.some((chip) => ['5', '8', '12'].includes(chip.label)),
            false,
        );
    });

    it('shows partial resolved plus unsupported terms', () => {
        const result = parsed({
            filters: { rank_id: '8' },
            applied: [{ key: 'rank:equals', label: 'Rank', value: 'AB' }],
            unresolved: [],
            ambiguous: [],
            unsupported: ['valid STCW'],
        });

        assert.deepEqual(result.unsupported, ['valid STCW']);
        assert.deepEqual(smartSearchResolvedPreview(result.applied), [
            { key: 'rank:equals', title: 'Rank', label: 'AB' },
        ]);
        assert.equal(hasApplyableSmartSearchFilters(result.filters), true);
    });

    it('does not treat unsupported-only results as applyable', () => {
        const result = parsed({
            filters: {},
            applied: [],
            unresolved: [],
            ambiguous: [],
            unsupported: ['Ford cars'],
        });

        assert.equal(hasApplyableSmartSearchFilters(result.filters), false);
        assert.deepEqual(result.unsupported, ['Ford cars']);
    });
});

describe('employee directory empty state and override copy', () => {
    it('does not treat unused Smart Search as an active empty-state cause', () => {
        assert.equal(hasActiveSmartSearchOwnedFilters({}), false);
        assert.equal(
            employeeDirectoryEmptyStateTitle(false),
            'No employees found.',
        );
        assert.equal(
            employeeDirectoryEmptyStateTitle(
                hasActiveSmartSearchOwnedFilters({}),
            ),
            'No employees found.',
        );
    });

    it('uses Smart-specific empty-state copy only when owned filters are active', () => {
        assert.equal(hasActiveSmartSearchOwnedFilters({ rank_id: '8' }), true);
        assert.equal(
            employeeDirectoryEmptyStateTitle(true),
            'No employees match the Smart Search and current directory filters.',
        );
    });

    it('does not treat unsupported-only results as active Smart filters', () => {
        const result = parsed({
            filters: {},
            applied: [],
            unresolved: [],
            ambiguous: [],
            unsupported: ['Ford cars'],
        });

        assert.equal(hasApplyableSmartSearchFilters(result.filters), false);
        assert.equal(hasActiveSmartSearchOwnedFilters(result.filters), false);
        assert.equal(
            smartSearchResultCopyKind({ result, previewChips: [] }),
            'unchanged',
        );
        assert.equal(
            employeeDirectoryEmptyStateTitle(
                hasActiveSmartSearchOwnedFilters(result.filters),
            ),
            'No employees found.',
        );
    });

    it('drops ownership and does not say unsupported after a manual override', () => {
        const owned = reconcileSmartSearchOwnership(
            { ...emptyFilters, rank_id: '44' },
            { rank_id: '8' },
        );
        const result = parsed({
            filters: { rank_id: '8' },
            applied: [{ key: 'rank:equals', label: 'Rank', value: 'AB' }],
            unresolved: [],
            ambiguous: [],
            unsupported: [],
        });
        const previewChips = smartSearchResolvedPreview(
            result.applied,
            { rank_id: '8' },
            { ...emptyFilters, rank_id: '44' },
        );

        assert.deepEqual(owned, {});
        assert.deepEqual(previewChips, []);
        assert.equal(hasActiveSmartSearchOwnedFilters(owned), false);
        assert.equal(
            smartSearchResultCopyKind({ result, previewChips }),
            'overridden',
        );
        assert.equal(
            SMART_SEARCH_OVERRIDDEN_COPY,
            'Smart Search filters are no longer active because the directory filters were changed.',
        );
        assert.notEqual(
            SMART_SEARCH_OVERRIDDEN_COPY,
            'No supported Smart Search filters were found.',
        );
    });
});

describe('employee active filter count', () => {
    it('counts each completeness condition and not the CSV containers', () => {
        assert.equal(
            employeeActiveFilterCount({
                ...emptyFilters,
                missing_fields: 'email,date_of_birth,nationality',
                present_fields: 'passport_number',
                rank_id: '5',
            }),
            5,
        );
        assert.equal(employeeActiveFilterCount(emptyFilters), 0);
        assert.equal(
            employeeActiveFilterCount({ ...emptyFilters, status: '' }),
            0,
        );
        assert.equal(
            employeeActiveFilterCount({
                ...emptyFilters,
                status: 'all',
                missing_fields: 'email',
            }),
            2,
        );
    });
});

describe('employee smart search response validation', () => {
    it('throws on malformed 200 payloads instead of applying empty filters', () => {
        assert.throws(
            () => parseSmartSearchResponse({ filters: { status: 'active' } }),
            SmartSearchMalformedResponseError,
        );
        assert.throws(
            () =>
                parseSmartSearchResponse({
                    filters: { nationality_id: '5' },
                    applied: [
                        { key: 'nationality:equals', label: '', value: '5' },
                    ],
                    unresolved: [],
                    ambiguous: [],
                    unsupported: [],
                }),
            SmartSearchMalformedResponseError,
        );
        assert.throws(
            () => parseSmartSearchResponse(null),
            SmartSearchMalformedResponseError,
        );
    });

    it('drops unexpected payload keys and unlabeled filter ids', () => {
        const result = parsed({
            filters: {
                status: 'active',
                manager_id: '10',
                nationality_id: '5',
            },
            applied: [
                { key: 'status:equals', label: 'HR status', value: 'Active' },
            ],
            unresolved: [],
            ambiguous: [],
            unsupported: [],
            employees: [{ id: 99, name: 'Secret Employee' }],
            company_id: 2,
            provider: 'openai',
        });

        assert.deepEqual(result.filters, { status: 'active' });
        assert.equal('employees' in result, false);
        assert.equal('company_id' in result, false);
        assert.equal('nationality_id' in result.filters, false);
    });
});

describe('employee smart search unresolved copy', () => {
    it('keeps unresolved and ambiguous values visible', () => {
        const result = parsed({
            filters: { status: 'active' },
            applied: [
                { key: 'status:equals', label: 'HR status', value: 'Active' },
            ],
            unresolved: [{ field: 'rank', term: 'XYZ', reason: 'not_found' }],
            ambiguous: [
                {
                    field: 'position',
                    term: 'Electrician',
                    reason: 'ambiguous',
                },
            ],
            unsupported: [],
        });

        assert.equal(
            formatUnresolvedItem(result.unresolved[0]),
            'Rank "XYZ" — No matching value found.',
        );
        assert.equal(
            formatUnresolvedItem(result.ambiguous[0]),
            'Position "Electrician" — Multiple matches found.',
        );
    });
});

describe('employee smart search error mapping', () => {
    it('maps 403, 422, 429, and 503 to user-friendly messages', () => {
        assert.equal(
            smartSearchErrorMessage(403),
            'Smart Employee Search is currently disabled.',
        );
        assert.equal(
            smartSearchErrorMessage(429),
            'Too many Smart Search requests. Try again shortly.',
        );
        assert.equal(
            smartSearchErrorMessage(503, {
                message: 'provider timeout with key sk-secret',
            }),
            'Smart Search is temporarily unavailable.',
        );
        assert.equal(parseRetryAfterSeconds('8'), 8);
        assert.equal(parseRetryAfterSeconds(null), null);
    });
});

describe('employee smart search prompt cache', () => {
    it('normalizes whitespace and case for cache keys', () => {
        assert.equal(
            normalizeSmartSearchPrompt('  Active   Filipino Crew  '),
            'Active Filipino Crew',
        );
        assert.equal(
            smartSearchCacheKey('Active Filipino Crew'),
            smartSearchCacheKey('active filipino crew'),
        );

        const cache = new SmartSearchInterpretationCache(2);
        const first = parsed({
            filters: { status: 'active' },
            applied: [
                { key: 'status:equals', label: 'HR status', value: 'Active' },
            ],
            unresolved: [],
            ambiguous: [],
            unsupported: [],
        });

        cache.set('  Active   Filipino Crew  ', first);

        assert.deepEqual(cache.get('active filipino crew'), first);

        cache.set('second', first);
        cache.set('third', first);

        assert.equal(cache.get('active filipino crew'), undefined);
        assert.equal(cache.size, 2);
        assert.equal(SMART_SEARCH_CACHE_LIMIT, 20);
        assert.equal(
            employeeDirectoryFiltersEqual({ a: '1' }, { a: '1' }),
            true,
        );
    });
});
