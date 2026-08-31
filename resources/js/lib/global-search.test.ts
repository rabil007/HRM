import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    GLOBAL_SEARCH_DEBOUNCE_MS,
    GLOBAL_SEARCH_MAX_QUERY_LENGTH,
    collectNavGroupUrls,
    commandItemSearchValue,
    commandResultValue,
    destinationMatchesQuery,
    filterCommandGroupsForQuery,
    filterFavoritesForQuery,
    flattenNavCommands,
    isCommandPaletteHotkey,
    isStaleSearchResponse,
    orderedRecordGroups,
    RECORD_GROUP_ORDER,
    recordSearchEmptyMessage,
    shouldRequestRecordSearch,
    shouldUseCmdkClientFilter,
} from './global-search.ts';
import type { GlobalSearchGroup } from './global-search.ts';

describe('command palette shortcut', () => {
    it('still opens on Cmd/Ctrl+K', () => {
        assert.equal(
            isCommandPaletteHotkey({ key: 'k', metaKey: true, ctrlKey: false }),
            true,
        );
        assert.equal(
            isCommandPaletteHotkey({ key: 'k', metaKey: false, ctrlKey: true }),
            true,
        );
        assert.equal(
            isCommandPaletteHotkey({
                key: 'k',
                metaKey: false,
                ctrlKey: false,
            }),
            false,
        );
    });
});

describe('shouldRequestRecordSearch', () => {
    it('does not query empty or short input', () => {
        assert.equal(shouldRequestRecordSearch(''), false);
        assert.equal(shouldRequestRecordSearch(' '), false);
        assert.equal(shouldRequestRecordSearch('a'), false);
        assert.equal(shouldRequestRecordSearch(' a '), false);
    });

    it('queries trimmed input at the minimum length', () => {
        assert.equal(shouldRequestRecordSearch('ab'), true);
        assert.equal(shouldRequestRecordSearch(' ab '), true);
    });

    it('rejects overly long queries instead of sending them', () => {
        assert.equal(
            shouldRequestRecordSearch(
                'a'.repeat(GLOBAL_SEARCH_MAX_QUERY_LENGTH),
            ),
            true,
        );
        assert.equal(
            shouldRequestRecordSearch(
                'a'.repeat(GLOBAL_SEARCH_MAX_QUERY_LENGTH + 1),
            ),
            false,
        );
    });
});

describe('stale response protection', () => {
    it('ignores responses that are not the latest request', () => {
        assert.equal(isStaleSearchResponse(1, 2), true);
        assert.equal(isStaleSearchResponse(2, 2), false);
    });
});

describe('orderedRecordGroups', () => {
    it('hides empty groups and keeps the configured order', () => {
        const groups: GlobalSearchGroup[] = [
            { key: 'payroll', label: 'Payroll', results: [] },
            {
                key: 'documents',
                label: 'Documents',
                results: [
                    {
                        id: 'document:1',
                        title: 'Passport',
                        subtitle: 'EMP-0012',
                        href: '/organization/documents/employee/1/files/1',
                    },
                ],
            },
            {
                key: 'employees',
                label: 'Employees',
                results: [
                    {
                        id: 'employee:1',
                        title: 'Ada',
                        subtitle: 'EMP-0012',
                        href: '/organization/employees/1',
                    },
                ],
            },
        ];

        assert.deepEqual(
            orderedRecordGroups(groups).map((group) => group.key),
            ['employees', 'documents'],
        );
    });

    it('never renders a category the backend omitted', () => {
        const groups: GlobalSearchGroup[] = [
            {
                key: 'employees',
                label: 'Employees',
                results: [
                    {
                        id: 'employee:1',
                        title: 'Ada',
                        subtitle: 'EMP-0012',
                        href: '/organization/employees/1',
                    },
                ],
            },
        ];

        assert.equal(
            orderedRecordGroups(groups).some(
                (group) => group.key === 'documents',
            ),
            false,
        );
    });
});

describe('command palette copy and values', () => {
    it('uses a 250ms record-search debounce', () => {
        assert.equal(GLOBAL_SEARCH_DEBOUNCE_MS, 250);
    });

    it('distinguishes loading, error, and empty states', () => {
        assert.equal(
            recordSearchEmptyMessage({ loading: true, error: false }),
            'Searching…',
        );
        assert.equal(
            recordSearchEmptyMessage({ loading: false, error: true }),
            'Search failed. Try again.',
        );
        assert.equal(
            recordSearchEmptyMessage({ loading: false, error: false }),
            'No results found.',
        );
    });

    it('keeps record items visible to cmdk for the current query', () => {
        assert.match(
            commandResultValue('ada', {
                id: 'employee:1',
                title: 'Ada Lovelace',
                subtitle: 'EMP-0012 · Marine',
                href: '/organization/employees/1',
            }),
            /ada Ada Lovelace EMP-0012/,
        );
    });
});

describe('flattenNavCommands', () => {
    it('keeps Phase 3A navigation commands including nested items', () => {
        const navGroups: Parameters<typeof flattenNavCommands>[0] = [
            {
                title: 'General',
                items: [{ title: 'Dashboard', url: '/dashboard' }],
            },
            {
                title: 'Organization',
                items: [
                    {
                        title: 'Crew',
                        items: [
                            {
                                title: 'Current Crew',
                                url: '/organization/crew',
                            },
                        ],
                    },
                ],
            },
        ];

        const commands = flattenNavCommands(navGroups);

        assert.deepEqual(
            commands.map((command) => command.title),
            ['Dashboard', 'Crew / Current Crew'],
        );
        assert.equal(
            commands.some((command) => command.url === '/dashboard'),
            true,
        );
    });
});

describe('record-search destination filtering', () => {
    it('disables cmdk client filtering once record search is active', () => {
        assert.equal(shouldUseCmdkClientFilter(''), true);
        assert.equal(shouldUseCmdkClientFilter('a'), true);
        assert.equal(shouldUseCmdkClientFilter('ab'), false);
        assert.equal(shouldUseCmdkClientFilter(' mohammed '), false);
    });

    it('keeps matching destinations under record results and hides unrelated ones', () => {
        const groups = [
            {
                title: 'Employees',
                items: [
                    { title: 'Employees', url: '/organization/employees' },
                    { title: 'Documents', url: '/organization/documents' },
                ],
            },
            {
                title: 'Crew Operations',
                items: [
                    { title: 'Vessels', url: '/organization/vessels' },
                    {
                        title: 'Planning',
                        url: '/organization/crew-planning',
                    },
                ],
            },
        ];

        const filtered = filterCommandGroupsForQuery(groups, 'vessel');

        assert.deepEqual(
            filtered.map((group) => ({
                title: group.title,
                items: group.items.map((item) => item.title),
            })),
            [{ title: 'Crew Operations', items: ['Vessels'] }],
        );
        assert.equal(
            destinationMatchesQuery(
                { title: 'Employees', value: 'Employees' },
                'vessel',
            ),
            false,
        );
        assert.deepEqual(
            filterFavoritesForQuery(
                [{ title: 'Employees' }, { title: 'Vessels' }],
                'ves',
            ).map((item) => item.title),
            ['Vessels'],
        );
        assert.deepEqual(
            filterCommandGroupsForQuery(groups, '').map((group) => group.title),
            ['Employees', 'Crew Operations'],
        );
    });

    it('finds Settings Master Data destinations by title and group context', () => {
        const groups = [
            {
                title: 'Employees',
                items: [{ title: 'Employees', url: '/organization/employees' }],
            },
            {
                title: 'Settings · Master Data',
                items: [
                    {
                        title: 'Projects',
                        url: '/settings/master-data/projects',
                    },
                    {
                        title: 'Ranks',
                        url: '/settings/master-data/ranks',
                    },
                    {
                        title: 'Countries',
                        url: '/settings/master-data/countries',
                    },
                    {
                        title: 'Banks',
                        url: '/settings/master-data/banks',
                    },
                    {
                        title: 'Clients',
                        url: '/settings/master-data/clients',
                    },
                    {
                        title: 'Visa types',
                        url: '/settings/master-data/visa-types',
                    },
                    {
                        title: 'Approval locations',
                        url: '/settings/master-data/approval-locations',
                    },
                    {
                        title: 'SSSA options',
                        url: '/settings/master-data/sssa-options',
                    },
                    {
                        title: 'Vessel types',
                        url: '/settings/master-data/vessel-types',
                    },
                    {
                        title: 'Courses',
                        url: '/settings/master-data/courses',
                    },
                    {
                        title: 'Genders',
                        url: '/settings/master-data/genders',
                    },
                    {
                        title: 'Religions',
                        url: '/settings/master-data/religions',
                    },
                    {
                        title: 'Currencies',
                        url: '/settings/master-data/currencies',
                    },
                ],
            },
        ];

        const queries: Array<[string, string]> = [
            ['project', 'Projects'],
            ['projects', 'Projects'],
            ['rank', 'Ranks'],
            ['ranks', 'Ranks'],
            ['country', 'Countries'],
            ['countries', 'Countries'],
            ['bank', 'Banks'],
            ['banks', 'Banks'],
            ['client', 'Clients'],
            ['clients', 'Clients'],
            ['visa', 'Visa types'],
            ['approval location', 'Approval locations'],
            ['SSSA', 'SSSA options'],
            ['vessel type', 'Vessel types'],
            ['course', 'Courses'],
            ['gender', 'Genders'],
            ['religion', 'Religions'],
            ['currency', 'Currencies'],
            ['master data project', 'Projects'],
        ];

        for (const [query, title] of queries) {
            const filtered = filterCommandGroupsForQuery(groups, query);
            const titles = filtered.flatMap((group) =>
                group.items.map((item) => item.title),
            );

            assert.equal(
                titles.includes(title),
                true,
                `expected "${query}" to find "${title}"`,
            );
        }

        assert.deepEqual(
            filterCommandGroupsForQuery(groups, 'visa type').map(
                (group) => group.title,
            ),
            ['Settings · Master Data'],
        );
    });

    it('does not change record-search category order or favorites matching', () => {
        assert.deepEqual(RECORD_GROUP_ORDER, [
            'employees',
            'documents',
            'crew',
            'vessels',
            'payroll',
            'departments',
            'positions',
        ]);
        assert.deepEqual(
            filterFavoritesForQuery(
                [{ title: 'Employees' }, { title: 'Vessels' }],
                'ves',
            ).map((item) => item.title),
            ['Vessels'],
        );
        assert.deepEqual(
            collectNavGroupUrls([
                {
                    title: 'General',
                    items: [
                        {
                            title: 'Settings',
                            items: [
                                {
                                    title: 'Security',
                                    url: '/settings/security',
                                },
                            ],
                        },
                    ],
                },
            ]),
            new Set(['/settings/security']),
        );
        assert.equal(
            commandItemSearchValue('Settings · Master Data', 'Projects'),
            'Settings · Master Data Projects',
        );
    });
});
