import assert from 'node:assert/strict';
import test from 'node:test';

import type { PermissionOption } from '@/features/organization/roles/types';

import { permissionMatchesQuery } from './role-permission-search.ts';

const samplePermission: PermissionOption = {
    id: 1,
    name: 'payroll.records.view',
    label: 'View Payroll Records',
    description:
        'Allows the user to view payroll periods, employee payroll records, calculations, and related payroll information.',
    group: 'Payroll',
};

test('permission search matches label, key, description, and group', () => {
    assert.equal(
        permissionMatchesQuery(samplePermission, 'payroll records'),
        true,
    );
    assert.equal(permissionMatchesQuery(samplePermission, 'salary'), false);
    assert.equal(
        permissionMatchesQuery(samplePermission, 'calculations'),
        true,
    );
    assert.equal(permissionMatchesQuery(samplePermission, 'Payroll'), true);
    assert.equal(
        permissionMatchesQuery(samplePermission, 'payroll.records.view'),
        true,
    );
});

test('permission search treats empty query as match-all', () => {
    assert.equal(permissionMatchesQuery(samplePermission, ''), true);
    assert.equal(permissionMatchesQuery(samplePermission, '   '), true);
});

test('permission search finds regrouped modules and user-facing subgroup names', () => {
    const permission = (
        name: string,
        label: string,
        group: string,
    ): PermissionOption => ({
        id: 2,
        name,
        label,
        description: '',
        group,
    });

    assert.equal(
        permissionMatchesQuery(
            permission(
                'settings.master-data.countries.view',
                'View Countries',
                'Master Data',
            ),
            'Master Data',
        ),
        true,
    );
    assert.equal(
        permissionMatchesQuery(
            permission(
                'settings.master-data.countries.view',
                'View Countries',
                'Master Data',
            ),
            'Countries',
        ),
        true,
    );
    assert.equal(
        permissionMatchesQuery(
            permission(
                'settings.master-data.company-visa-types.view',
                'View Company visa types',
                'Master Data',
            ),
            'Sponsors',
        ),
        true,
    );
    assert.equal(
        permissionMatchesQuery(
            permission(
                'settings.integrations.hikvision.view',
                'View Hikvision Integration',
                'Integrations',
            ),
            'Hikvision',
        ),
        true,
    );
    assert.equal(
        permissionMatchesQuery(
            permission(
                'settings.master-data.document-types.view',
                'View Document types',
                'Employee Documents',
            ),
            'Document Types',
        ),
        true,
    );
    assert.equal(
        permissionMatchesQuery(
            permission(
                'crew_operations.vessels.view',
                'View Vessels',
                'Crew Operations',
            ),
            'Vessels',
        ),
        true,
    );
});
