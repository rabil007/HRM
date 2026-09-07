import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { resolvePermissionGroups } from './role-permission-groups.ts';

describe('role permission grouping', () => {
    it('places library document permissions under DOCUMENTS / GENERAL', () => {
        assert.deepEqual(resolvePermissionGroups('documents.view'), {
            mainGroup: 'DOCUMENTS',
            subGroup: 'GENERAL',
        });
        assert.deepEqual(resolvePermissionGroups('documents.upload'), {
            mainGroup: 'DOCUMENTS',
            subGroup: 'GENERAL',
        });
    });

    it('places bulk document permissions under DOCUMENTS / GENERATE & TRACK', () => {
        assert.deepEqual(resolvePermissionGroups('bulk_documents.view'), {
            mainGroup: 'DOCUMENTS',
            subGroup: 'GENERATE & TRACK',
        });
        assert.deepEqual(resolvePermissionGroups('bulk_documents.generate'), {
            mainGroup: 'DOCUMENTS',
            subGroup: 'GENERATE & TRACK',
        });
    });

    it('does not produce BULK DOCUMENTS as a top-level category', () => {
        const mainGroups = new Set(
            [
                'documents.view',
                'documents.templates.view',
                'bulk_documents.view',
                'bulk_documents.generate',
                'bulk_documents.delete',
                'bulk_documents.email',
            ].map(
                (permission) => resolvePermissionGroups(permission).mainGroup,
            ),
        );

        assert.deepEqual([...mainGroups], ['DOCUMENTS']);
        assert.equal(mainGroups.has('BULK DOCUMENTS'), false);
    });

    it('keeps existing nested document subgroups', () => {
        assert.deepEqual(resolvePermissionGroups('documents.templates.view'), {
            mainGroup: 'DOCUMENTS',
            subGroup: 'TEMPLATES',
        });
        assert.deepEqual(resolvePermissionGroups('documents.requests.view'), {
            mainGroup: 'DOCUMENTS',
            subGroup: 'REQUESTS',
        });
        assert.deepEqual(
            resolvePermissionGroups('documents.recipient-requests.view'),
            {
                mainGroup: 'DOCUMENTS',
                subGroup: 'RECIPIENT REQUESTS',
            },
        );
    });

    it('leaves unrelated permission categories unchanged', () => {
        assert.deepEqual(resolvePermissionGroups('employees.view'), {
            mainGroup: 'EMPLOYEES',
            subGroup: 'GENERAL',
        });
        assert.deepEqual(resolvePermissionGroups('company_documents.view'), {
            mainGroup: 'COMPANIES',
            subGroup: 'DOCUMENTS',
        });
        assert.deepEqual(resolvePermissionGroups('settings.appearance.view'), {
            mainGroup: 'SETTINGS',
            subGroup: 'APPEARANCE',
        });
        assert.deepEqual(
            resolvePermissionGroups('crew_operations.overview.view'),
            {
                mainGroup: 'CREW OPERATIONS',
                subGroup: 'OVERVIEW',
            },
        );
    });
});
