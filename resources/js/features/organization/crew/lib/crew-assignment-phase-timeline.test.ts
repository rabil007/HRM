import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';
import type {
    CrewAssignmentDetail,
    CrewAssignmentPagePermissions,
    PhaseTimelineItem,
} from '../types.ts';

function phase(overrides: Partial<PhaseTimelineItem> = {}): PhaseTimelineItem {
    return {
        id: 10,
        sequence: 1,
        phase_code: 'p4',
        phase_label: 'On Vessel',
        status: 'active',
        status_label: 'Active',
        planned_start_at: '2026-09-01',
        planned_end_at: '2026-09-15',
        actual_start_at: '2026-09-03',
        actual_end_at: null,
        details: null,
        remarks: null,
        has_pending_correction: false,
        has_approved_correction: false,
        ...overrides,
    };
}

function assignment(
    overrides: Partial<CrewAssignmentDetail> = {},
): CrewAssignmentDetail {
    return {
        id: 1,
        assignment_no: 'CA-2026-000001',
        status: 'active',
        status_label: 'Active',
        company_timezone: 'Asia/Dubai',
        current_phase: {
            id: 10,
            code: 'p4',
            label: 'On Vessel',
        },
        phase_timeline: [phase()],
        planned_arrival_at: null,
        actual_arrival_at: null,
        planned_join_at: null,
        actual_join_at: null,
        planned_travel_at: null,
        planned_signoff_at: null,
        actual_disembarkation_at: null,
        started_at: null,
        closed_at: null,
        employee: { id: 5, name: 'Alex Crew' },
        ...overrides,
    } as CrewAssignmentDetail;
}

const can = {
    view_corrections: true,
    request_correction: true,
    override_corrections: true,
    view_training: true,
} as Pick<
    CrewAssignmentPagePermissions,
    | 'view_corrections'
    | 'request_correction'
    | 'override_corrections'
    | 'view_training'
>;

async function renderTimeline(detail: CrewAssignmentDetail): Promise<string> {
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
        const { CrewAssignmentPhaseTimeline } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew/components/crew-assignment-phase-timeline.tsx',
        );

        return renderToString(
            React.createElement(CrewAssignmentPhaseTimeline, {
                assignment: detail,
                can,
                correctablePhaseIds: new Set<number>(),
                onCorrect: () => undefined,
                onCancelPending: () => undefined,
            }),
        );
    } finally {
        await vite.close();
    }
}

describe('CrewAssignmentPhaseTimeline render', () => {
    it('defaults to Plan vs Actual controls and shows phase timeline', async () => {
        const html = await renderTimeline(assignment());

        assert.ok(html.includes('Phase Timeline'));
        assert.ok(html.includes('Plan vs Actual'));
        assert.ok(html.includes('Actual Only'));
        assert.ok(html.includes('Planned Only'));
        assert.ok(html.includes('On Vessel'));
        assert.ok(html.includes('aria-selected="true"'));
    });

    it('renders historical completed timelines without inventing open elapsed ends', async () => {
        const html = await renderTimeline(
            assignment({
                status: 'completed',
                status_label: 'Completed',
                current_phase: null,
                phase_timeline: [
                    phase({
                        status: 'completed',
                        status_label: 'Completed',
                        actual_end_at: '2026-09-17',
                    }),
                ],
            }),
        );

        assert.ok(html.includes('Completed'));
        assert.ok(!html.includes('In progress'));
    });
});
