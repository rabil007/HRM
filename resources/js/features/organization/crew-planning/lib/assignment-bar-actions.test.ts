import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';
import type { GanttBar, PlanningPagePermissions } from '../types';

const baseBar: GanttBar = {
    id: 123,
    row_key: 'vessel-1-pos-2',
    employee_id: 456,
    employee_name: 'Captain John',
    start: '2027-04-01',
    end: '2027-09-30',
    planned_arrival_date: '2027-03-25',
    planned_join_date: '2027-04-01',
    planned_leave_date: '2027-09-30',
    is_open_ended: false,
    total_days: 182,
    position_name: 'Master',
    vessel_name: 'Ocean Pioneer',
    notes: 'Primary captain forecast',
    crew_assignment_id: null,
    relieves_crew_assignment_id: null,
    relieves_employee_name: null,
    is_assigned: false,
};

const basePermissions: PlanningPagePermissions = {
    view: true,
    create: true,
    update: true,
    delete: true,
    projection: true,
    start_assignment: true,
};

async function renderActions(
    bar: GanttBar,
    permissions: PlanningPagePermissions,
): Promise<string> {
    const vite = await createServer({
        configFile: false,
        plugins: [(await import('@vitejs/plugin-react')).default()],
        resolve: {
            alias: {
                '@': new URL('../../../../', import.meta.url).pathname,
            },
        },
    });

    try {
        const { AssignmentBarActions } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew-planning/components/assignment-bar-actions.tsx',
        );

        return renderToString(
            React.createElement(AssignmentBarActions, {
                bar,
                can: permissions,
            }),
        );
    } finally {
        await vite.close();
    }
}

describe('AssignmentBarActions component test', () => {
    it('renders Start Mobilisation on unlinked named bar when start_assignment is true', async () => {
        const html = await renderActions(baseBar, basePermissions);

        assert.ok(html.includes('Start Mobilisation'));
        assert.ok(html.includes('Edit'));
        assert.ok(html.includes('Delete'));
        assert.ok(!html.includes('Open Crew Assignment'));
    });

    it('does not render Start Mobilisation on unlinked named bar when start_assignment is false', async () => {
        const html = await renderActions(baseBar, {
            ...basePermissions,
            start_assignment: false,
        });

        assert.ok(!html.includes('Start Mobilisation'));
        assert.ok(html.includes('Edit'));
        assert.ok(html.includes('Delete'));
        assert.ok(!html.includes('Open Crew Assignment'));
    });

    it('does not render Start Mobilisation on vacant planning bar', async () => {
        const vacantBar: GanttBar = {
            ...baseBar,
            employee_id: null,
            employee_name: 'Vacant Slot',
        };

        const html = await renderActions(vacantBar, basePermissions);

        assert.ok(!html.includes('Start Mobilisation'));
        assert.ok(html.includes('Edit'));
        assert.ok(html.includes('Delete'));
        assert.ok(!html.includes('Open Crew Assignment'));
    });

    it('renders Open Crew Assignment and hides Start Mobilisation on linked planning bar', async () => {
        const linkedBar: GanttBar = {
            ...baseBar,
            crew_assignment_id: 789,
        };

        const html = await renderActions(linkedBar, basePermissions);

        assert.ok(html.includes('Open Crew Assignment'));
        assert.ok(!html.includes('Start Mobilisation'));
        assert.ok(!html.includes('Edit'));
        assert.ok(!html.includes('Delete'));
    });
});
