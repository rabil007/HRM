import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';
import type {
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
} from '../types.ts';

const defaultPermissions: Pick<
    CrewAssignmentPagePermissions,
    'view' | 'update' | 'perform_movement' | 'cancel' | 'view_planning'
> = {
    view: true,
    update: true,
    perform_movement: true,
    cancel: true,
    view_planning: true,
};

function makeFormOptions(
    overrides: Partial<CrewAssignmentCreateFormOptions> = {},
): CrewAssignmentCreateFormOptions {
    return {
        employees: [
            {
                id: 42,
                name: 'Ranjan Rai Virendra Rai',
                employee_no: 'EMP-3119',
                rank_id: 7,
                image: null,
                nationality_name: 'Indian',
            },
        ],
        ranks: [{ id: 7, name: 'Chief Engineer' }],
        vessels: [],
        clients: [],
        courses: [],
        company_timezone: 'Asia/Dubai',
        max_home_days: 30,
        employee_status_by_employee: {
            '42': {
                status: 'on_vessel',
                label: 'On vessel',
                current_phase: 'p4',
                current_vessel: 'Ocean Star',
                assignment_id: 100,
                assignment_no: 'CA-2026-000042',
                since: '2026-09-02T08:00:00+04:00',
                days_in_phase: 16,
                planned_next_date: null,
                warning: null,
                in_home_days: null,
                vessel_name: 'Ocean Star',
                has_active_assignment: true,
            },
        },
        active_on_vessel_by_employee: {
            '42': {
                assignment_id: 100,
                assignment_no: 'CA-2026-000042',
                employee_id: 42,
                employee_name: 'Ranjan Rai Virendra Rai',
                vessel_id: 8,
                vessel_name: 'Ocean Star',
                phase_id: 501,
                actual_start_at: '2026-09-02T08:00:00+04:00',
                actual_start_display: '02 Sep 2026 08:00',
                status: 'on_vessel',
                can_transfer: true,
            },
        },
        ...overrides,
    };
}

async function renderReadinessPanel(options: {
    employeeId: number | null;
    formOptions?: CrewAssignmentCreateFormOptions;
    destinationVesselId?: number | null;
    permissions?: typeof defaultPermissions;
}): Promise<string> {
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
        const { CrewAssignmentReadinessPanel } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew/components/crew-assignment-readiness-panel.tsx',
        );

        return renderToString(
            React.createElement(CrewAssignmentReadinessPanel, {
                employeeId: options.employeeId,
                formOptions: options.formOptions ?? makeFormOptions(),
                permissions: options.permissions ?? defaultPermissions,
                destinationVesselId: options.destinationVesselId ?? null,
            }),
        );
    } finally {
        await vite.close();
    }
}

describe('CrewAssignmentReadinessPanel render component test', () => {
    it('shows the empty state when no employee is selected', async () => {
        const html = await renderReadinessPanel({ employeeId: null });

        assert.ok(html.includes('data-slot="assignment-readiness-empty"'));
        assert.ok(
            html.includes(
                'Select an employee to view their crew movement journey',
            ),
        );
        assert.ok(!html.includes('data-slot="assignment-readiness-employee"'));
    });

    it('renders selected employee identity and replaces the empty state', async () => {
        const html = await renderReadinessPanel({ employeeId: 42 });

        assert.ok(!html.includes('data-slot="assignment-readiness-empty"'));
        assert.ok(html.includes('data-slot="assignment-readiness-employee"'));
        assert.ok(html.includes('Ranjan Rai Virendra Rai'));
        assert.ok(html.includes('EMP-3119'));
        assert.ok(html.includes('Chief Engineer'));
        assert.ok(html.includes('Indian'));
    });

    it('renders operational status and assignment context for selected employee', async () => {
        const html = await renderReadinessPanel({ employeeId: 42 });

        assert.ok(html.includes('CA-2026-000042'));
        assert.ok(html.includes('Ocean Star'));
        assert.ok(html.includes('On Vessel'));
        assert.ok(html.includes('What this means'));
        assert.ok(html.includes('Recommended actions'));
    });
});
