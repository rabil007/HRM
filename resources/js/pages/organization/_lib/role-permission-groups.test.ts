import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    isVisibleRolePermission,
    preserveHiddenRolePermissions,
    resolvePermissionGroups,
} from './role-permission-groups.ts';

describe('role permission grouping', () => {
    it('uses registry group as the authoritative main category', () => {
        assert.deepEqual(
            resolvePermissionGroups('documents.view', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Library',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups(
                'crew_operations.assignments.create',
                'Crew Operations',
            ),
            {
                mainGroup: 'Crew Operations',
                subGroup: 'Assignments',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups('employees.view', 'Employees'),
            {
                mainGroup: 'Employees',
                subGroup: 'General',
            },
        );
    });

    it('places library document permissions under Documents / Library', () => {
        assert.deepEqual(
            resolvePermissionGroups('documents.view', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Library',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups('documents.upload', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Library',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups('documents.download', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Library',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups('documents.share', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Library',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups('documents.delete', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Library',
            },
        );
    });

    it('places document templates permissions under Documents / Templates', () => {
        assert.deepEqual(
            resolvePermissionGroups('documents.templates.view', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Templates',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups('documents.templates.create', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Templates',
            },
        );
    });

    it('places bulk document permissions under Documents / Generate & Track', () => {
        assert.deepEqual(
            resolvePermissionGroups('bulk_documents.view', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Generate & Track',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups('bulk_documents.generate', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Generate & Track',
            },
        );
    });

    it('places document requests permissions under Documents / Requests', () => {
        assert.deepEqual(
            resolvePermissionGroups('documents.requests.view', 'Documents'),
            {
                mainGroup: 'Documents',
                subGroup: 'Requests',
            },
        );
    });

    it('places recipient-requests permissions under Documents / Recipient Requests', () => {
        assert.deepEqual(
            resolvePermissionGroups(
                'documents.recipient-requests.view',
                'Documents',
            ),
            {
                mainGroup: 'Documents',
                subGroup: 'Recipient Requests',
            },
        );
    });

    it('places signing-presets permissions under Documents / Signing Presets', () => {
        assert.deepEqual(
            resolvePermissionGroups(
                'documents.signing-presets.view',
                'Documents',
            ),
            {
                mainGroup: 'Documents',
                subGroup: 'Signing Presets',
            },
        );
    });

    it('places workflow-presets permissions under Documents / Workflow Presets', () => {
        assert.deepEqual(
            resolvePermissionGroups(
                'documents.workflow-presets.view',
                'Documents',
            ),
            {
                mainGroup: 'Documents',
                subGroup: 'Workflow Presets',
            },
        );
    });

    it('places recipient-automation permissions under Documents / Recipient Automation', () => {
        assert.deepEqual(
            resolvePermissionGroups(
                'documents.recipient-automation.view',
                'Documents',
            ),
            {
                mainGroup: 'Documents',
                subGroup: 'Recipient Automation',
            },
        );
    });

    it('places document-types permissions under Documents / Document Types', () => {
        assert.deepEqual(
            resolvePermissionGroups(
                'settings.master-data.document-types.view',
                'Documents',
            ),
            {
                mainGroup: 'Documents',
                subGroup: 'Document Types',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups(
                'settings.master-data.document-types.create',
                'Documents',
            ),
            {
                mainGroup: 'Documents',
                subGroup: 'Document Types',
            },
        );
    });

    it('does not produce a top-level Employee Documents category', () => {
        const documentPermissions = [
            ['documents.view', 'Documents'],
            ['documents.upload', 'Documents'],
            ['documents.download', 'Documents'],
            ['documents.share', 'Documents'],
            ['documents.delete', 'Documents'],
            ['documents.templates.view', 'Documents'],
            ['documents.templates.create', 'Documents'],
            ['documents.requests.view', 'Documents'],
            ['documents.recipient-requests.view', 'Documents'],
            ['documents.signing-presets.view', 'Documents'],
            ['documents.workflow-presets.view', 'Documents'],
            ['documents.recipient-automation.view', 'Documents'],
            ['bulk_documents.view', 'Documents'],
            ['bulk_documents.generate', 'Documents'],
            ['bulk_documents.delete', 'Documents'],
            ['bulk_documents.email', 'Documents'],
            ['settings.master-data.document-types.view', 'Documents'],
        ] as const;

        const mainGroups = new Set(
            documentPermissions.map(
                ([permission, group]) =>
                    resolvePermissionGroups(permission, group).mainGroup,
            ),
        );

        assert.equal(mainGroups.size, 1);
        assert.equal([...mainGroups][0], 'Documents');
        assert.equal(mainGroups.has('Employee Documents'), false);
        assert.equal(mainGroups.has('Bulk Documents'), false);
    });

    it('does not produce Bulk Documents as a top-level category', () => {
        const mainGroups = new Set(
            [
                ['documents.view', 'Documents'],
                ['documents.templates.view', 'Documents'],
                ['bulk_documents.view', 'Documents'],
                ['bulk_documents.generate', 'Documents'],
                ['bulk_documents.delete', 'Documents'],
                ['bulk_documents.email', 'Documents'],
            ].map(
                ([permission, group]) =>
                    resolvePermissionGroups(permission, group).mainGroup,
            ),
        );

        assert.deepEqual([...mainGroups], ['Documents']);
        assert.equal(mainGroups.has('Bulk Documents'), false);
    });

    it('derives subgroups from permission names while preserving registry main groups', () => {
        assert.deepEqual(
            resolvePermissionGroups('company_documents.view', 'Companies'),
            {
                mainGroup: 'Companies',
                subGroup: 'Documents',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups('settings.appearance.view', 'Settings'),
            {
                mainGroup: 'Settings',
                subGroup: 'Appearance',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups(
                'crew_operations.overview.view',
                'Crew Operations',
            ),
            {
                mainGroup: 'Crew Operations',
                subGroup: 'Overview',
            },
        );
    });

    it('falls back to a name-based main group when registry group is missing', () => {
        assert.deepEqual(resolvePermissionGroups('employees.view'), {
            mainGroup: 'Employees',
            subGroup: 'General',
        });
    });

    it('uses current Master Data names without repeating the main category', () => {
        const areas = {
            countries: 'Countries',
            currencies: 'Currencies',
            'visa-types': 'Visa Types',
            'company-visa-types': 'Sponsors',
            'approval-locations': 'Approval Locations',
            'sssa-options': 'SSSA Options',
            religions: 'Religions',
            genders: 'Genders',
            courses: 'Courses',
            banks: 'Banks',
            'vessel-types': 'Vessel Types',
            ranks: 'Ranks',
            clients: 'Clients',
            projects: 'Projects',
            hotels: 'Hotels',
        };

        for (const [area, subGroup] of Object.entries(areas)) {
            assert.deepEqual(
                resolvePermissionGroups(
                    `settings.master-data.${area}.view`,
                    'Master Data',
                ),
                { mainGroup: 'Master Data', subGroup },
            );
        }

        assert.deepEqual(
            resolvePermissionGroups(
                'settings.master-data.clients.update',
                'Master Data',
            ),
            { mainGroup: 'Master Data', subGroup: 'Clients' },
        );
    });

    it('keeps Settings, Integrations, Documents and Crew Operations distinct', () => {
        const cases = [
            ['settings.security.view', 'Settings', 'Security'],
            ['settings.appearance.view', 'Settings', 'Appearance'],
            [
                'settings.integrations.hikvision.view',
                'Integrations',
                'Hikvision',
            ],
            [
                'settings.master-data.document-types.view',
                'Documents',
                'Document Types',
            ],
            ['crew_operations.vessels.view', 'Crew Operations', 'Vessels'],
        ];

        for (const [name, mainGroup, subGroup] of cases) {
            assert.deepEqual(resolvePermissionGroups(name, mainGroup), {
                mainGroup,
                subGroup,
            });
        }
    });

    it('hides legacy Settings vessel choices while preserving existing assignments', () => {
        const legacy = 'settings.master-data.vessels.view';
        const current = 'crew_operations.vessels.view';

        assert.equal(isVisibleRolePermission(legacy), false);
        assert.equal(isVisibleRolePermission(current), true);
        assert.deepEqual(
            preserveHiddenRolePermissions([current], [legacy, current]),
            [current, legacy],
        );
        assert.deepEqual(preserveHiddenRolePermissions([], [legacy, current]), [
            legacy,
        ]);
    });
});
