import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    collectNavGroupUrls,
    excludeOccupiedCommandGroups,
} from './global-search.ts';
import {
    filterSettingsNavItems,
    NO_PLATFORM_ACCESS,
} from './nav-visibility.ts';

const PLATFORM_VIEW = {
    view: true,
    manage: false,
    database: false,
} as const;

const MASTER_DATA_ITEMS = [
    {
        title: 'Countries',
        href: '/settings/master-data/countries',
        permission: 'settings.master-data.countries.view',
    },
    {
        title: 'Banks',
        href: '/settings/master-data/banks',
        permission: 'settings.master-data.banks.view',
    },
    {
        title: 'Ranks',
        href: '/settings/master-data/ranks',
        permission: 'settings.master-data.ranks.view',
    },
    {
        title: 'Clients',
        href: '/settings/master-data/clients',
        permission: 'settings.master-data.clients.view',
    },
    {
        title: 'Projects',
        href: '/settings/master-data/projects',
        permission: 'settings.master-data.projects.view',
    },
] as const;

const SYSTEM_ITEMS = [
    {
        title: 'Application',
        href: '/settings/application',
        platformOnly: true,
    },
    {
        title: 'WhatsApp templates',
        href: '/settings/application/whatsapp-templates',
        platformOnly: true,
    },
    {
        title: 'Security',
        href: '/settings/security',
        permission: 'settings.security.view',
    },
] as const;

describe('settings command destinations', () => {
    it('shows Banks only with banks.view', () => {
        const visible = filterSettingsNavItems(
            [...MASTER_DATA_ITEMS],
            ['settings.master-data.banks.view'],
        );
        const hidden = filterSettingsNavItems(
            [...MASTER_DATA_ITEMS],
            ['employees.view'],
        );

        assert.deepEqual(
            visible.map((item) => item.title),
            ['Banks'],
        );
        assert.equal(
            hidden.some((item) => item.title === 'Banks'),
            false,
        );
    });

    it('shows Projects only with projects.view', () => {
        const visible = filterSettingsNavItems(
            [...MASTER_DATA_ITEMS],
            ['settings.master-data.projects.view'],
        );
        const hidden = filterSettingsNavItems(
            [...MASTER_DATA_ITEMS],
            ['settings.master-data.banks.view'],
        );

        assert.deepEqual(
            visible.map((item) => item.title),
            ['Projects'],
        );
        assert.equal(
            hidden.some((item) => item.title === 'Projects'),
            false,
        );
    });

    it('filters other Master Data destinations by their own view permission', () => {
        const titles = filterSettingsNavItems(
            [...MASTER_DATA_ITEMS],
            [
                'settings.master-data.banks.view',
                'settings.master-data.countries.view',
            ],
        ).map((item) => item.title);

        assert.equal(titles.includes('Banks'), true);
        assert.equal(titles.includes('Countries'), true);
        assert.equal(titles.includes('Ranks'), false);
        assert.equal(titles.includes('Clients'), false);
    });

    it('does not leak platform-only Application settings to tenant-only users', () => {
        const tenantOnly = filterSettingsNavItems(
            [...SYSTEM_ITEMS],
            ['settings.security.view', 'settings.master-data.projects.view'],
            NO_PLATFORM_ACCESS,
        );
        const platformUser = filterSettingsNavItems(
            [...SYSTEM_ITEMS],
            [],
            PLATFORM_VIEW,
        );

        assert.deepEqual(
            tenantOnly.map((item) => item.title),
            ['Security'],
        );
        assert.equal(
            platformUser.some((item) => item.title === 'Application'),
            true,
        );
        assert.equal(
            tenantOnly.some((item) => item.title === 'Application'),
            false,
        );
    });

    it('does not duplicate a destination already present in the sidebar', () => {
        const groups = excludeOccupiedCommandGroups(
            [
                {
                    title: 'Settings · System',
                    items: [{ title: 'Security', url: '/settings/security' }],
                },
                {
                    title: 'Settings · Master Data',
                    items: [
                        {
                            title: 'Projects',
                            url: '/settings/master-data/projects',
                        },
                    ],
                },
            ],
            new Set(['/settings/security', '/settings/security/']),
        );

        assert.deepEqual(
            groups.map((group) => ({
                title: group.title,
                items: group.items.map((item) => item.title),
            })),
            [
                {
                    title: 'Settings · Master Data',
                    items: ['Projects'],
                },
            ],
        );
    });

    it('recomputes accessible commands when company permissions change', () => {
        const companyA = filterSettingsNavItems(
            [...MASTER_DATA_ITEMS],
            ['settings.master-data.banks.view'],
        );
        const companyB = filterSettingsNavItems(
            [...MASTER_DATA_ITEMS],
            ['settings.master-data.projects.view'],
        );

        assert.deepEqual(
            companyA.map((item) => item.title),
            ['Banks'],
        );
        assert.deepEqual(
            companyB.map((item) => item.title),
            ['Projects'],
        );
    });

    it('keeps extra Settings commands out of occupied sidebar URLs', () => {
        const sidebarGroups = [
            {
                title: 'Employees',
                items: [
                    { title: 'Employees', url: '/organization/employees' },
                    { title: 'Documents', url: '/organization/documents' },
                ],
            },
            {
                title: 'General',
                items: [
                    {
                        title: 'Settings',
                        items: [
                            { title: 'Overview', url: '/settings' },
                            { title: 'Security', url: '/settings/security' },
                        ],
                    },
                ],
            },
        ];
        const extra = excludeOccupiedCommandGroups(
            [
                {
                    title: 'Settings · System',
                    items: [{ title: 'Security', url: '/settings/security' }],
                },
                {
                    title: 'Settings · Master Data',
                    items: [
                        {
                            title: 'Projects',
                            url: '/settings/master-data/projects',
                        },
                    ],
                },
            ],
            collectNavGroupUrls(sidebarGroups),
        );

        assert.equal(
            extra.some((group) =>
                group.items.some((item) => item.url === '/settings/security'),
            ),
            false,
        );
        assert.equal(
            extra.some((group) =>
                group.items.some(
                    (item) => item.url === '/settings/master-data/projects',
                ),
            ),
            true,
        );
    });
});
