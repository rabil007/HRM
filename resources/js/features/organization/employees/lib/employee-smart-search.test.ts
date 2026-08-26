import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    SMART_SEARCH_FILTER_KEYS,
    buildEmployeeSmartSearchRequestBody,
    formatUnresolvedItem,
    hasApplyableSmartSearchFilters,
    mergeSmartSearchFilters,
    normalizeSmartSearchResponse,
    pickAllowlistedSmartSearchFilters,
    smartSearchErrorMessage,
    smartSearchResolvedPreview,
} from './employee-smart-search.ts';

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
    });
});

describe('employee smart search filter allowlist', () => {
    it('includes only the six supported directory keys', () => {
        assert.deepEqual(SMART_SEARCH_FILTER_KEYS, [
            'status',
            'department_id',
            'position_id',
            'nationality_id',
            'rank_id',
            'crew_status',
        ]);
    });

    it('ignores arbitrary unexpected response filter keys', () => {
        const picked = pickAllowlistedSmartSearchFilters({
            status: 'active',
            nationality_id: '5',
            manager_id: '10',
            gender_id: '2',
            company_id: '99',
            search: 'secret employee',
            salary: '9000',
        });

        assert.deepEqual(picked, {
            status: 'active',
            nationality_id: '5',
        });
        assert.equal('manager_id' in picked, false);
        assert.equal('company_id' in picked, false);
        assert.equal('search' in picked, false);
    });
});

describe('employee smart search filter merge', () => {
    it('overwrites returned supported fields and preserves unrelated filters', () => {
        const merged = mergeSmartSearchFilters(
            {
                department_id: '',
                position_id: '',
                status: 'inactive',
                manager_id: '10',
                gender_id: '2',
                nationality_id: '',
                visa_type_id: '7',
                company_visa_type_id: '',
                rank_id: '',
                approval_location_id: '',
                sssa_option_id: '',
                crew_status: '',
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
        assert.equal(merged.department_id, '');
        assert.equal('search' in merged, false);
    });

    it('clears a stale position when Smart Search returns only department', () => {
        const merged = mergeSmartSearchFilters(
            {
                department_id: '10',
                position_id: '21',
                manager_id: '9',
                gender_id: '2',
                status: 'inactive',
            },
            {
                department_id: '15',
                status: 'active',
            },
        );

        assert.equal(merged.department_id, '15');
        assert.equal(merged.position_id, '');
        assert.equal(merged.manager_id, '9');
        assert.equal(merged.gender_id, '2');
        assert.equal(merged.status, 'active');
    });

    it('clears a stale department when Smart Search returns only position', () => {
        const merged = mergeSmartSearchFilters(
            {
                department_id: '10',
                position_id: '',
                role_id: '3',
            },
            {
                position_id: '31',
            },
        );

        assert.equal(merged.department_id, '');
        assert.equal(merged.position_id, '31');
        assert.equal(merged.role_id, '3');
    });

    it('applies both department and position when Smart Search returns both', () => {
        const merged = mergeSmartSearchFilters(
            {
                department_id: '10',
                position_id: '21',
                manager_id: '9',
            },
            {
                department_id: '15',
                position_id: '44',
            },
        );

        assert.equal(merged.department_id, '15');
        assert.equal(merged.position_id, '44');
        assert.equal(merged.manager_id, '9');
    });

    it('preserves existing department and position when neither is returned', () => {
        const merged = mergeSmartSearchFilters(
            {
                department_id: '10',
                position_id: '21',
                nationality_id: '',
            },
            {
                nationality_id: '5',
            },
        );

        assert.equal(merged.department_id, '10');
        assert.equal(merged.position_id, '21');
        assert.equal(merged.nationality_id, '5');
    });
});

describe('employee smart search preview', () => {
    it('uses labels rather than numeric IDs', () => {
        const chips = smartSearchResolvedPreview(
            {
                status: 'active',
                nationality_id: '5',
                rank_id: '8',
                department_id: '12',
            },
            {
                status: 'Active',
                nationality: 'Philippines',
                rank: 'AB',
                department: 'Crewing',
            },
        );

        assert.deepEqual(
            chips.map((chip) => `${chip.title} · ${chip.label}`),
            [
                'Status · Active',
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

    it('keeps unresolved values visible', () => {
        const result = normalizeSmartSearchResponse({
            filters: { status: 'active' },
            labels: { status: 'Active' },
            unresolved: [
                {
                    field: 'position',
                    term: 'Electrician',
                    reason: 'ambiguous',
                },
                {
                    field: 'rank',
                    term: 'XYZ',
                    reason: 'not_found',
                },
            ],
            unsupported: [],
        });

        assert.equal(result.unresolved.length, 2);
        assert.equal(
            formatUnresolvedItem(result.unresolved[0]),
            'Position "Electrician" — Multiple matches found.',
        );
        assert.equal(
            formatUnresolvedItem(result.unresolved[1]),
            'Rank "XYZ" — No matching value found.',
        );
        assert.equal(
            formatUnresolvedItem({
                field: 'department',
                term: 'Ops',
                reason: 'provider_quirk',
            }),
            'Department "Ops" — Could not resolve this value.',
        );
    });

    it('keeps unsupported terms visible', () => {
        const result = normalizeSmartSearchResponse({
            filters: { rank_id: '8' },
            labels: { rank: 'AB' },
            unresolved: [],
            unsupported: ['valid STCW'],
        });

        assert.deepEqual(result.unsupported, ['valid STCW']);
        assert.deepEqual(
            smartSearchResolvedPreview(result.filters, result.labels),
            [{ key: 'rank', title: 'Rank', label: 'AB' }],
        );
    });

    it('does not treat empty resolved filters as applyable', () => {
        const result = normalizeSmartSearchResponse({
            filters: {},
            labels: {},
            unresolved: [
                {
                    field: 'department',
                    term: 'Unknown',
                    reason: 'not_found',
                },
            ],
            unsupported: ['valid STCW'],
        });

        assert.equal(hasApplyableSmartSearchFilters(result.filters), false);
        assert.equal(result.unresolved.length, 1);
        assert.deepEqual(result.unsupported, ['valid STCW']);
    });
});

describe('employee smart search error mapping', () => {
    it('maps 403, 422, 429, and 503 to user-friendly messages', () => {
        assert.equal(
            smartSearchErrorMessage(403, {
                message: 'Employee smart search is not enabled.',
            }),
            'Smart Employee Search is currently disabled.',
        );
        assert.equal(
            smartSearchErrorMessage(422, {
                message: 'The prompt field must be at least 2 characters.',
                errors: {
                    prompt: ['The prompt field must be at least 2 characters.'],
                },
            }),
            'The prompt field must be at least 2 characters.',
        );
        assert.equal(
            smartSearchErrorMessage(422, {
                errors: {
                    prompt: ['The prompt field is required.'],
                },
            }),
            'The prompt field is required.',
        );
        assert.equal(
            smartSearchErrorMessage(429, { message: 'Too Many Attempts.' }),
            'Too many Smart Search requests. Try again shortly.',
        );
        assert.equal(
            smartSearchErrorMessage(503, {
                message: 'provider timeout with key sk-secret',
            }),
            'Smart Search is temporarily unavailable. Try again.',
        );
        assert.equal(
            smartSearchErrorMessage(500, {
                message: 'stack trace should not leak',
            }),
            'Smart Search could not be completed. Try again.',
        );
    });
});

describe('employee smart search response normalization', () => {
    it('drops unexpected payload keys and non-string filter values', () => {
        const result = normalizeSmartSearchResponse({
            filters: {
                status: 'active',
                manager_id: '10',
                employees: '1',
            },
            labels: { status: 'Active' },
            unresolved: [],
            unsupported: [],
            employees: [{ id: 99, name: 'Secret Employee' }],
            company_id: 2,
            provider: 'openai',
        });

        assert.deepEqual(result.filters, { status: 'active' });
        assert.equal('employees' in result, false);
        assert.equal('company_id' in result, false);
        assert.equal('provider' in result, false);
    });
});
