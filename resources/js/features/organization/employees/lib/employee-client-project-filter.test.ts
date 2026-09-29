import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { ProjectOption } from '../types.ts';
import {
    EMPTY_EMPLOYEE_FILTERS,
    filterProjectsByClient,
    projectHasClient,
    resolveProjectOnClientChange,
} from './employee-client-project-filter.ts';

const mockProjects: ProjectOption[] = [
    { id: 10, title: 'Project A', client_id: 1 },
    { id: 20, title: 'Project B', client_id: 1 },
    { id: 30, title: 'Project C', client_id: 2 },
    { id: 40, title: 'Project D', client_id: null },
];

describe('employee client-project filter behavior', () => {
    it('returns all projects when no client is selected', () => {
        const result = filterProjectsByClient(mockProjects, '');

        assert.equal(result.length, 4);
        assert.deepEqual(result, mockProjects);
    });

    it('treats missing projects as an empty list', () => {
        assert.deepEqual(filterProjectsByClient(undefined, ''), []);
        assert.deepEqual(filterProjectsByClient(undefined, '1'), []);
        assert.equal(resolveProjectOnClientChange('10', '1', undefined), '');
    });

    it('returns only projects belonging to the selected client', () => {
        const client1Projects = filterProjectsByClient(mockProjects, '1');

        assert.equal(client1Projects.length, 2);
        assert.deepEqual(
            client1Projects.map((p) => p.title),
            ['Project A', 'Project B'],
        );

        const client2Projects = filterProjectsByClient(mockProjects, '2');

        assert.equal(client2Projects.length, 1);
        assert.deepEqual(
            client2Projects.map((p) => p.title),
            ['Project C'],
        );
    });

    it('clears project when changing to a client that does not own the project', () => {
        // Project A (id: 10) belongs to Client 1. Changing to Client 2 clears it.
        const nextProject = resolveProjectOnClientChange(
            '10',
            '2',
            mockProjects,
        );

        assert.equal(nextProject, '');
    });

    it('preserves project when changing to the client that owns the project', () => {
        // Project A (id: 10) belongs to Client 1. Re-selecting or selecting Client 1 keeps it.
        const nextProject = resolveProjectOnClientChange(
            '10',
            '1',
            mockProjects,
        );

        assert.equal(nextProject, '10');
    });

    it('clears unmapped project when selecting any specific client', () => {
        // Project D (id: 40) has client_id: null. Selecting Client 1 clears it.
        const nextProject = resolveProjectOnClientChange(
            '40',
            '1',
            mockProjects,
        );

        assert.equal(nextProject, '');
    });

    it('preserves selected project when client is cleared', () => {
        // Project A (id: 10) is selected. Clearing client keeps Project A.
        const nextProject = resolveProjectOnClientChange(
            '10',
            '',
            mockProjects,
        );

        assert.equal(nextProject, '10');

        // And the full project list returns
        const allProjects = filterProjectsByClient(mockProjects, '');
        assert.equal(allProjects.length, 4);
    });

    it('verifies EMPTY_EMPLOYEE_FILTERS has both client_id and project_id blank', () => {
        assert.equal(EMPTY_EMPLOYEE_FILTERS.client_id, '');
        assert.equal(EMPTY_EMPLOYEE_FILTERS.project_id, '');
    });

    it('verifies EMPTY_EMPLOYEE_FILTERS does not contain branch_id or crew_status', () => {
        assert.equal('branch_id' in EMPTY_EMPLOYEE_FILTERS, false);
        assert.equal('crew_status' in EMPTY_EMPLOYEE_FILTERS, false);
    });

    it('verifies reset filters restores all fields to empty string', () => {
        const reset = { ...EMPTY_EMPLOYEE_FILTERS };

        for (const [key, value] of Object.entries(reset)) {
            assert.equal(
                value,
                '',
                `Expected ${key} to be empty string after reset`,
            );
        }

        assert.equal('branch_id' in reset, false);
        assert.equal('crew_status' in reset, false);
    });

    it('supports multi-client projects filtering and client switching', () => {
        const clientAId = '100';
        const clientBId = '200';
        const clientCId = '300';

        const multiProjects: ProjectOption[] = [
            { id: 1, title: 'Project 1', client_ids: [100, 200] },
            { id: 2, title: 'Project 2', client_ids: [100] },
            { id: 3, title: 'Project 3', client_ids: [200] },
        ];

        // Selecting Client A: Project 1, Project 2
        const clientAProjects = filterProjectsByClient(
            multiProjects,
            clientAId,
        );
        assert.deepEqual(
            clientAProjects.map((p) => p.title),
            ['Project 1', 'Project 2'],
        );

        // Selecting Client B: Project 1, Project 3
        const clientBProjects = filterProjectsByClient(
            multiProjects,
            clientBId,
        );
        assert.deepEqual(
            clientBProjects.map((p) => p.title),
            ['Project 1', 'Project 3'],
        );

        // Client A + Project 1 -> switch Client to B: Project 1 remains selected
        const switchAToB = resolveProjectOnClientChange(
            '1',
            clientBId,
            multiProjects,
        );
        assert.equal(switchAToB, '1');

        // Client A + Project 1 -> switch to unrelated Client C: Project cleared
        const switchAToC = resolveProjectOnClientChange(
            '1',
            clientCId,
            multiProjects,
        );
        assert.equal(switchAToC, '');

        // Client A + Project 2 -> switch Client to B: Project 2 is not mapped to B, so cleared
        const switchProject2ToB = resolveProjectOnClientChange(
            '2',
            clientBId,
            multiProjects,
        );
        assert.equal(switchProject2ToB, '');
    });

    it('makes client_ids authoritative over legacy client_id when client_ids has entries', () => {
        const projects: ProjectOption[] = [
            {
                id: 1,
                title: 'Project 1',
                client_ids: [200], // Client B
                client_id: 100, // Client A (legacy transitional)
            },
            {
                id: 2,
                title: 'Legacy Project 2',
                client_ids: [],
                client_id: 100, // Client A (legacy compatibility)
            },
        ];

        // client_ids = [B], client_id = A:
        // Client A -> Project 1 hidden
        const forClientA = filterProjectsByClient(projects, '100');
        assert.deepEqual(
            forClientA.map((p) => p.title),
            ['Legacy Project 2'],
        );

        // Client B -> Project 1 available
        const forClientB = filterProjectsByClient(projects, '200');
        assert.deepEqual(
            forClientB.map((p) => p.title),
            ['Project 1'],
        );

        // Also preserve: client_ids = [], client_id = A -> Client A legacy Project available
        assert.equal(projectHasClient(projects[1], '100'), true);
        assert.equal(projectHasClient(projects[1], '200'), false);

        // Resolve project on client change:
        // Switching to Client A clears Project 1 because Client A is not in client_ids
        assert.equal(resolveProjectOnClientChange('1', '100', projects), '');
        // Switching to Client B keeps Project 1
        assert.equal(resolveProjectOnClientChange('1', '200', projects), '1');
    });
});
