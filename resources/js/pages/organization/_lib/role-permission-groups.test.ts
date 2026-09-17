import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { resolvePermissionGroups } from './role-permission-groups.ts';

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
});
