import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    appendRequirementClientOption,
    appendRequirementProjectOption,
    filterRequirementProjectsForClient,
    selectableRequirementClients,
    selectableRequirementProjectsForClient,
    formatRequirementProjectCreateLabel,
    resolveRequirementProjectAfterClientChange,
    syncRequirementClientOptions,
    syncRequirementProjectOptions,
} from './requirement-form-client-project.ts';

describe('requirement form client/project helpers', () => {
    it('syncs local option state without mutating the source arrays', () => {
        const clients = [{ id: 1, name: 'Acme', is_active: true }];
        const projects = [
            { id: 10, title: 'Site A', client_ids: [1], is_active: true },
        ];

        const nextClients = syncRequirementClientOptions(clients);
        const nextProjects = syncRequirementProjectOptions(projects);

        assert.notEqual(nextClients, clients);
        assert.notEqual(nextProjects, projects);
        assert.deepEqual(nextClients, clients);
        assert.deepEqual(nextProjects, projects);
    });

    it('appends a newly created client only once', () => {
        const clients = [{ id: 1, name: 'Acme', is_active: true }];
        const withNew = appendRequirementClientOption(clients, {
            id: 2,
            label: 'Beta',
        });

        assert.equal(withNew.length, 2);
        assert.deepEqual(withNew[1], {
            id: 2,
            name: 'Beta',
            is_active: true,
        });
        assert.equal(
            appendRequirementClientOption(withNew, { id: 2, label: 'Beta' })
                .length,
            2,
        );
    });

    it('appends a project with the selected client_ids and merges on reuse', () => {
        const projects = [
            { id: 10, title: 'Shared', client_ids: [1], is_active: true },
        ];

        const created = appendRequirementProjectOption(
            [],
            { id: 11, label: 'New Site' },
            2,
        );
        assert.deepEqual(created[0], {
            id: 11,
            title: 'New Site',
            client_ids: [2],
            is_active: true,
        });

        const attached = appendRequirementProjectOption(
            projects,
            { id: 10, label: 'Shared' },
            2,
        );
        assert.deepEqual(attached[0].client_ids, [1, 2]);
    });

    it('filters projects by selected client and clears incompatible selections', () => {
        const projects = [
            { id: 10, title: 'A', client_ids: [1], is_active: true },
            { id: 11, title: 'B', client_ids: [2], is_active: true },
            { id: 12, title: 'Shared', client_ids: [1, 2], is_active: true },
        ];

        assert.deepEqual(
            filterRequirementProjectsForClient(projects, 1).map((p) => p.id),
            [10, 12],
        );
        assert.deepEqual(filterRequirementProjectsForClient(projects, ''), []);

        assert.equal(
            resolveRequirementProjectAfterClientChange('10', '2', projects),
            '',
        );
        assert.equal(
            resolveRequirementProjectAfterClientChange('12', '2', projects),
            '12',
        );
    });

    it('keeps inactive selected client visible while excluding other inactive options', () => {
        const clients = [
            { id: 1, name: 'Active', is_active: true },
            { id: 2, name: 'Inactive', is_active: false },
        ];

        assert.deepEqual(
            selectableRequirementClients(clients, 2).map((client) => client.id),
            [1, 2],
        );
        assert.deepEqual(
            selectableRequirementClients(clients, null).map(
                (client) => client.id,
            ),
            [1],
        );
    });

    it('keeps stale inactive project visible for drafts without listing other inactive projects', () => {
        const projects = [
            { id: 10, title: 'Active', client_ids: [1], is_active: true },
            { id: 11, title: 'Inactive', client_ids: [1], is_active: false },
        ];

        assert.deepEqual(
            selectableRequirementProjectsForClient(projects, 1, 11).map(
                (project) => project.id,
            ),
            [10, 11],
        );
        assert.deepEqual(
            selectableRequirementProjectsForClient(projects, 1, null).map(
                (project) => project.id,
            ),
            [10],
        );
    });

    it('formats the project create label with the selected client name', () => {
        assert.equal(
            formatRequirementProjectCreateLabel('Dockside', 'Acme Marine'),
            'Create "Dockside" for Acme Marine',
        );
        assert.equal(
            formatRequirementProjectCreateLabel('Dockside', null),
            'Create "Dockside"',
        );
    });
});
