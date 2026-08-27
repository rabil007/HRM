import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { isSidebarUrlVisible } from './nav-visibility.ts';
import {
    destinationKeyFromPageUrl,
    destinationKeyFromPathname,
    excludeUrlsFromNavGroups,
    isFavoriteDestinationKey,
    NAVIGATION_DESTINATIONS,
    pathnameFromPageUrl,
    resolveAccessibleFavoriteItems,
} from './navigation-favorites.ts';

describe('pathname matching', () => {
    it('strips query strings and trailing slashes', () => {
        assert.equal(
            pathnameFromPageUrl('/organization/employees?page=2'),
            '/organization/employees',
        );
        assert.equal(pathnameFromPageUrl('/dashboard/'), '/dashboard');
    });

    it('maps module indexes and nested record pages to destination keys', () => {
        assert.equal(
            destinationKeyFromPathname('/organization/employees'),
            'employees',
        );
        assert.equal(
            destinationKeyFromPathname('/organization/employees/12'),
            'employees',
        );
        assert.equal(
            destinationKeyFromPathname('/organization/documents/bulk'),
            'documents.bulk',
        );
        assert.equal(
            destinationKeyFromPathname('/organization/documents/generate'),
            'documents.bulk',
        );
        assert.equal(
            destinationKeyFromPathname('/organization/documents/library'),
            'documents.library',
        );
        assert.equal(
            destinationKeyFromPathname('/organization/documents'),
            'documents',
        );
        assert.equal(
            destinationKeyFromPathname('/organization/documents/employees/12'),
            'documents.library',
        );
        assert.equal(
            destinationKeyFromPathname(
                '/organization/documents/employees/12/files/55',
            ),
            'documents.library',
        );
        assert.equal(
            destinationKeyFromPathname('/payroll/overview'),
            'payroll.overview',
        );
        assert.equal(
            destinationKeyFromPathname('/organization/vessels'),
            'crew.vessels',
        );
        assert.equal(
            destinationKeyFromPathname('/organization/vessel-manning/4'),
            'crew.vessel-manning',
        );
        assert.equal(destinationKeyFromPathname('/settings/application'), null);
        assert.equal(
            destinationKeyFromPageUrl(
                '/organization/documents/bulk?view=signatures',
            ),
            'documents.requests',
        );
        assert.equal(
            destinationKeyFromPageUrl(
                '/organization/documents/bulk?view=history',
            ),
            'documents.activity',
        );
    });
});

describe('unified Documents destinations', () => {
    it('keeps Documents as one group without a standalone Bulk generate destination', () => {
        const documents = NAVIGATION_DESTINATIONS.filter(
            (destination) => destination.group === 'Documents',
        );

        assert.deepEqual(
            documents.map((destination) => destination.label),
            [
                'Overview',
                'Library',
                'Generate & Send',
                'Requests',
                'Templates',
                'Activity',
            ],
        );
        assert.equal(
            NAVIGATION_DESTINATIONS.some(
                (destination) => destination.label === 'Bulk generate',
            ),
            false,
        );
        assert.equal(
            NAVIGATION_DESTINATIONS.some(
                (destination) =>
                    destination.href === '/organization/documents/bulk',
            ),
            false,
        );
    });
});

describe('accessible favorite rendering', () => {
    it('shows the favorites list only when at least one key is currently visible', () => {
        const keys = ['employees', 'documents', 'legacy.removed'];

        assert.deepEqual(
            resolveAccessibleFavoriteItems(keys, (href) =>
                isSidebarUrlVisible(href, ['employees.view']),
            ).map((item) => item.key),
            ['employees'],
        );
        assert.deepEqual(
            resolveAccessibleFavoriteItems(keys, (href) =>
                isSidebarUrlVisible(href, ['departments.view']),
            ),
            [],
        );
    });

    it('preserves insertion order and skips unknown keys', () => {
        const items = resolveAccessibleFavoriteItems(
            ['documents', 'employees', 'documents', 'not-a-key'],
            (href) =>
                isSidebarUrlVisible(href, ['employees.view', 'documents.view']),
        );

        assert.deepEqual(
            items.map((item) => item.key),
            ['documents', 'employees'],
        );
    });

    it('hides platform destinations without platform visibility', () => {
        const keys = ['platform.logs', 'employees'];

        assert.deepEqual(
            resolveAccessibleFavoriteItems(keys, (href) =>
                isSidebarUrlVisible(href, ['employees.view']),
            ).map((item) => item.key),
            ['employees'],
        );
        assert.deepEqual(
            resolveAccessibleFavoriteItems(keys, (href) =>
                isSidebarUrlVisible(href, ['employees.view'], {
                    view: true,
                    manage: false,
                    database: false,
                }),
            ).map((item) => item.key),
            ['platform.logs', 'employees'],
        );
    });

    it('lets view-only destinations stay favorited', () => {
        assert.equal(
            isFavoriteDestinationKey(['employees'], 'employees'),
            true,
        );
        assert.deepEqual(
            resolveAccessibleFavoriteItems(['employees'], (href) =>
                isSidebarUrlVisible(href, ['employees.view']),
            ).map((item) => item.url),
            ['/organization/employees'],
        );
        assert.equal(
            isSidebarUrlVisible('/organization/employees', ['employees.view']),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/employees', [
                'employees.create',
            ]),
            false,
        );
    });

    it('changes visibility when company permissions change without deleting the key', () => {
        const stored = ['employees'];

        assert.equal(
            resolveAccessibleFavoriteItems(stored, (href) =>
                isSidebarUrlVisible(href, ['employees.view']),
            ).length,
            1,
        );
        assert.equal(
            resolveAccessibleFavoriteItems(stored, (href) =>
                isSidebarUrlVisible(href, ['departments.view']),
            ).length,
            0,
        );
        assert.equal(isFavoriteDestinationKey(stored, 'employees'), true);
    });

    it('lets users.view without create keep Users favorited', () => {
        assert.equal(
            isSidebarUrlVisible('/organization/users', ['users.view']),
            true,
        );
        assert.equal(
            isSidebarUrlVisible('/organization/users', ['users.create']),
            false,
        );
        assert.deepEqual(
            resolveAccessibleFavoriteItems(['organization.users'], (href) =>
                isSidebarUrlVisible(href, ['users.view']),
            ).map((item) => item.key),
            ['organization.users'],
        );
    });

    it('keeps payroll favorites and hides employees for payroll-only users', () => {
        assert.deepEqual(
            resolveAccessibleFavoriteItems(['employees', 'payroll'], (href) =>
                isSidebarUrlVisible(href, ['payroll.periods.view']),
            ).map((item) => item.key),
            ['payroll'],
        );
    });

    it('shows dashboard only when no module navigation permissions match', () => {
        assert.deepEqual(
            resolveAccessibleFavoriteItems(
                ['dashboard', 'employees', 'payroll'],
                (href) => isSidebarUrlVisible(href, []),
            ).map((item) => item.key),
            ['dashboard'],
        );
    });

    it('does not introduce record-level favorite keys', () => {
        assert.equal(
            destinationKeyFromPathname('/organization/employees/12'),
            'employees',
        );
        assert.equal(
            NAVIGATION_DESTINATIONS.some((destination) =>
                /(?:^|\.)\d+$/.test(destination.key),
            ),
            false,
        );
    });
});

describe('command palette de-duplication', () => {
    it('removes nested Documents destinations from commands when favorited', () => {
        const groups = excludeUrlsFromNavGroups(
            [
                {
                    title: 'Employees',
                    items: [
                        {
                            title: 'Documents',
                            items: [
                                {
                                    title: 'Overview',
                                    url: '/organization/documents',
                                },
                                {
                                    title: 'Generate & Send',
                                    url: '/organization/documents/generate',
                                },
                            ],
                        },
                    ],
                },
            ],
            new Set(['/organization/documents/generate']),
        );

        assert.deepEqual(groups, [
            {
                title: 'Employees',
                items: [
                    {
                        title: 'Documents',
                        items: [
                            {
                                title: 'Overview',
                                url: '/organization/documents',
                            },
                        ],
                    },
                ],
            },
        ]);
    });

    it('removes favorite urls from the normal commands groups', () => {
        const groups = excludeUrlsFromNavGroups(
            [
                {
                    title: 'Employees',
                    items: [
                        { title: 'Employees', url: '/organization/employees' },
                        { title: 'Documents', url: '/organization/documents' },
                    ],
                },
            ],
            new Set(['/organization/employees']),
        );

        assert.deepEqual(groups, [
            {
                title: 'Employees',
                items: [{ title: 'Documents', url: '/organization/documents' }],
            },
        ]);
    });
});

describe('catalog alignment', () => {
    it('keeps destination hrefs unique and permission-gated except dashboard', () => {
        const hrefs = NAVIGATION_DESTINATIONS.map(
            (destination) => destination.href,
        );

        assert.equal(hrefs.length, new Set(hrefs).size);

        for (const destination of NAVIGATION_DESTINATIONS) {
            if (destination.key === 'dashboard') {
                assert.equal(isSidebarUrlVisible(destination.href, []), true);
                continue;
            }

            assert.equal(isSidebarUrlVisible(destination.href, []), false);
        }
    });
});
