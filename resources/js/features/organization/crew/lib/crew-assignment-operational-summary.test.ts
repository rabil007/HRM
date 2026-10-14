import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';
import type { CrewAssignmentDetail } from '../types.ts';

function assignment(
    overrides: Partial<CrewAssignmentDetail> = {},
): CrewAssignmentDetail {
    return {
        id: 1,
        assignment_no: 'CA-2026-000001',
        status: 'active',
        status_label: 'Active',
        current_phase: {
            id: 10,
            code: 'p0',
            label: 'Pre-Mobilisation',
        },
        phase_timeline: [],
        planned_arrival_at: '2026-10-18',
        planned_join_at: null,
        planned_signoff_at: null,
        planned_travel_at: null,
        actual_arrival_at: null,
        actual_join_at: null,
        actual_disembarkation_at: null,
        started_at: '2026-10-14',
        closed_at: null,
        warnings: [],
        mobilisation_readiness: null,
        recommended_action: null,
        ...overrides,
    } as CrewAssignmentDetail;
}

async function renderSummary(detail: CrewAssignmentDetail): Promise<string> {
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
        const { CrewAssignmentOperationalSummary } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew/components/crew-assignment-operational-summary.tsx',
        );

        return renderToString(
            React.createElement(CrewAssignmentOperationalSummary, {
                assignment: detail,
            }),
        );
    } finally {
        await vite.close();
    }
}

describe('CrewAssignmentOperationalSummary render', () => {
    it('shows assignment lifecycle from started_at and ongoing closed_at', async () => {
        const html = await renderSummary(assignment());

        assert.ok(html.includes('Assignment Lifecycle'));
        assert.ok(html.includes('Started'));
        assert.ok(html.includes('Ended'));
        assert.ok(html.includes('14-10-2026'));
        assert.ok(html.includes('Ongoing'));
    });

    it('shows not started for draft assignments without started_at', async () => {
        const html = await renderSummary(
            assignment({
                status: 'draft',
                status_label: 'Draft',
                started_at: null,
            }),
        );

        assert.ok(html.includes('Not started'));
    });

    it('shows authoritative closed_at for completed assignments', async () => {
        const html = await renderSummary(
            assignment({
                status: 'completed',
                status_label: 'Completed',
                closed_at: '2026-10-30',
            }),
        );

        assert.ok(html.includes('30-10-2026'));
        assert.ok(!html.includes('Ongoing'));
    });
});
