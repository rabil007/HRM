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
            resolvePermissionGroups('documents.view', 'Employee Documents'),
            {
                mainGroup: 'Employee Documents',
                subGroup: 'General',
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

    it('places library document permissions under Employee Documents / General', () => {
        assert.deepEqual(
            resolvePermissionGroups('documents.view', 'Employee Documents'),
            {
                mainGroup: 'Employee Documents',
                subGroup: 'General',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups('documents.upload', 'Employee Documents'),
            {
                mainGroup: 'Employee Documents',
                subGroup: 'General',
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

    it('does not produce Bulk Documents as a top-level category', () => {
        const mainGroups = new Set(
            [
                ['documents.view', 'Employee Documents'],
                ['documents.templates.view', 'Employee Documents'],
                ['bulk_documents.view', 'Documents'],
                ['bulk_documents.generate', 'Documents'],
                ['bulk_documents.delete', 'Documents'],
                ['bulk_documents.email', 'Documents'],
            ].map(
                ([permission, group]) =>
                    resolvePermissionGroups(permission, group).mainGroup,
            ),
        );

        assert.deepEqual([...mainGroups], ['Employee Documents', 'Documents']);
        assert.equal(mainGroups.has('Bulk Documents'), false);
    });

    it('keeps nested document subgroups from permission names', () => {
        assert.deepEqual(
            resolvePermissionGroups(
                'documents.templates.view',
                'Employee Documents',
            ),
            {
                mainGroup: 'Employee Documents',
                subGroup: 'Templates',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups(
                'documents.requests.view',
                'Employee Documents',
            ),
            {
                mainGroup: 'Employee Documents',
                subGroup: 'Requests',
            },
        );
        assert.deepEqual(
            resolvePermissionGroups(
                'documents.recipient-requests.view',
                'Employee Documents',
            ),
            {
                mainGroup: 'Employee Documents',
                subGroup: 'Recipient Requests',
            },
        );
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
                'Employee Documents',
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
