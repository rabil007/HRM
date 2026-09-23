import assert from 'node:assert/strict';
import test from 'node:test';
import type { RequirementFilters } from '../../../../../types/recruitment.ts';
import {
    buildRequirementQuery,
    clearedRequirementFilters,
} from './requirement-filters.ts';

const filters: RequirementFilters = {
    tab: 'on_hold',
    search: 'engineer',
    client_id: 12,
    project_id: 4,
    position_id: 8,
    assigned_to: 3,
    priority: 'urgent',
    deadline_health: 'overdue',
    per_page: 15,
};

test('summary shortcuts clear restrictive filters and search while retaining page size', () => {
    assert.deepEqual(
        buildRequirementQuery(filters, {
            ...clearedRequirementFilters,
            search: '',
            tab: 'active',
            deadline_health: 'due_soon',
        }),
        { tab: 'active', deadline_health: 'due_soon', per_page: 15 },
    );
});

test('quick filters retain the search and context and can be toggled off', () => {
    assert.deepEqual(buildRequirementQuery(filters, { priority: null }), {
        tab: 'on_hold',
        search: 'engineer',
        client_id: 12,
        project_id: 4,
        position_id: 8,
        assigned_to: 3,
        deadline_health: 'overdue',
        per_page: 15,
    });
});

test('page size changes reset pagination and subsequent pages retain it', () => {
    const current: RequirementFilters = {
        tab: 'active',
        search: '',
        per_page: 15,
    };
    assert.deepEqual(buildRequirementQuery(current, { per_page: 30 }), {
        tab: 'active',
        per_page: 30,
    });
    assert.deepEqual(
        buildRequirementQuery({ ...current, per_page: 30 }, {}, 2),
        { tab: 'active', per_page: 30, page: 2 },
    );
});
