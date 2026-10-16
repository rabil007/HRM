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

function activeCurrentP0Assignment(
    overrides: Partial<CrewAssignmentDetail> = {},
): CrewAssignmentDetail {
    return assignment({
        status: 'active',
        status_label: 'Active',
        planned_arrival_at: '2026-10-17',
        current_phase: {
            id: 11,
            code: 'p0',
            label: 'Pre-Arrival',
        },
        phase_timeline: [
            phase({
                id: 11,
                phase_code: 'p0',
                phase_label: 'Pre-Arrival',
                status: 'active',
                status_label: 'Active',
                planned_start_at: null,
                planned_end_at: null,
                actual_start_at: '2026-10-14',
                actual_end_at: null,
            }),
        ],
        ...overrides,
    });
}

function countOccurrences(haystack: string, needle: string): number {
    if (needle === '') {
        return 0;
    }

    let count = 0;
    let index = 0;

    while (index !== -1) {
        index = haystack.indexOf(needle, index);

        if (index === -1) {
            break;
        }

        count += 1;
        index += needle.length;
    }

    return count;
}

async function renderTimeline(
    detail: CrewAssignmentDetail,
    options?: {
        can?: Pick<
            CrewAssignmentPagePermissions,
            | 'view_corrections'
            | 'request_correction'
            | 'override_corrections'
            | 'view_training'
        >;
    },
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
        const { CrewAssignmentPhaseTimeline } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew/components/crew-assignment-phase-timeline.tsx',
        );

        return renderToString(
            React.createElement(CrewAssignmentPhaseTimeline, {
                assignment: detail,
                can: options?.can ?? can,
                correctablePhaseIds: new Set<number>([11]),
                onCorrect: () => undefined,
                onCancelPending: () => undefined,
            }),
        );
    } finally {
        await vite.close();
    }
}

describe('CrewAssignmentPhaseTimeline render', () => {
    it('shows one actual-movement timeline without display-mode tabs', async () => {
        const html = await renderTimeline(
            assignment({
                planned_signoff_at: '2026-10-25',
                current_phase: {
                    id: 10,
                    code: 'p2',
                    label: 'On Vessel',
                },
                phase_timeline: [
                    phase({
                        phase_code: 'p2',
                        phase_label: 'On Vessel',
                        actual_start_at: '2026-10-10',
                        planned_end_at: null,
                    }),
                ],
            }),
        );

        assert.ok(html.includes('Phase Timeline'));
        assert.ok(!html.includes('Plan vs Actual'));
        assert.ok(!html.includes('Actual Only'));
        assert.ok(!html.includes('Planned Only'));
        assert.ok(html.includes('On Vessel'));
        assert.ok(html.includes('Joined Vessel:'));
        assert.ok(html.includes('Planned Sign-Off:'));
        assert.ok(html.includes('25-10-2026'));
        assert.ok(!html.includes('aria-selected="true"'));
    });

    it('does not invent a planned sign-off on an active on-vessel phase', async () => {
        const html = await renderTimeline(
            assignment({
                current_phase: { id: 10, code: 'p2', label: 'On Vessel' },
                phase_timeline: [
                    phase({
                        phase_code: 'p2',
                        phase_label: 'On Vessel',
                        actual_start_at: '2026-10-10',
                        planned_end_at: null,
                    }),
                ],
            }),
        );

        assert.ok(html.includes('Sign-Off Not Planned'));
        assert.ok(!html.includes('Actual Movement:'));
    });

    it('renders expected arrival for active current P0 with assignment forecast', async () => {
        const html = await renderTimeline(activeCurrentP0Assignment());

        assert.ok(html.includes('Expected Arrival:'));
        assert.ok(html.includes('17-10-2026'));
        assert.ok(!html.includes('Planned:'));
        assert.ok(!html.includes('Not recorded'));
        assert.ok(!html.includes('variance unavailable (no planned dates)'));
        assert.equal(countOccurrences(html, 'Waiting for crew arrival'), 1);
        assert.ok(!html.includes('Actual:'));
        assert.ok(!html.includes('Duration:'));
        assert.ok(!html.includes('Phase details'));
    });

    it('shows the recorded P0 start when expected arrival is missing', async () => {
        const html = await renderTimeline(
            activeCurrentP0Assignment({ planned_arrival_at: null }),
        );

        assert.ok(html.includes('P0 Started:'));
        assert.ok(html.includes('14-10-2026'));
        assert.ok(!html.includes('variance unavailable (no planned dates)'));
        assert.equal(countOccurrences(html, 'Waiting for crew arrival'), 1);
    });

    it('does not show expected arrival on completed historical P0 after forecast update', async () => {
        const html = await renderTimeline(
            assignment({
                planned_arrival_at: '2026-12-01',
                current_phase: {
                    id: 20,
                    code: 'p2a',
                    label: 'Join Standby',
                },
                phase_timeline: [
                    phase({
                        id: 11,
                        phase_code: 'p0',
                        phase_label: 'Pre-Arrival',
                        status: 'completed',
                        status_label: 'Completed',
                        planned_start_at: null,
                        planned_end_at: null,
                        actual_start_at: '2026-10-01',
                        actual_end_at: '2026-10-10',
                    }),
                    phase({
                        id: 20,
                        phase_code: 'p2a',
                        phase_label: 'Join Standby',
                        status: 'active',
                        status_label: 'Active',
                        actual_start_at: '2026-10-11',
                    }),
                ],
            }),
        );

        assert.ok(!html.includes('Expected Arrival:'));
        assert.ok(html.includes('Completed'));
    });

    it('renders P2B training metadata without course_id or duplicate course in status', async () => {
        const html = await renderTimeline(
            assignment({
                current_phase: {
                    id: 30,
                    code: 'p2b',
                    label: 'Training',
                },
                phase_timeline: [
                    phase({
                        id: 30,
                        phase_code: 'p2b',
                        phase_label: 'Training',
                        status: 'completed',
                        status_label: 'Completed',
                        actual_start_at: '2026-09-01',
                        actual_end_at: '2026-09-05',
                        details: {
                            course_id: 15,
                            course: 'BOSIET',
                            provider: 'ABC Training',
                        },
                    }),
                ],
            }),
        );

        assert.ok(html.includes('Course'));
        assert.ok(html.includes('BOSIET'));
        assert.ok(html.includes('Training Provider'));
        assert.ok(html.includes('ABC Training'));
        assert.ok(!html.includes('course_id'));
        assert.equal(countOccurrences(html, 'BOSIET'), 1);
    });

    it('omits employee training link when view_training is denied', async () => {
        const html = await renderTimeline(
            assignment({
                current_phase: { id: 30, code: 'p2b', label: 'Training' },
                phase_timeline: [
                    phase({
                        id: 30,
                        phase_code: 'p2b',
                        phase_label: 'Training',
                        status: 'completed',
                        status_label: 'Completed',
                        employee_training_id: 99,
                        details: { course: 'BOSIET' },
                    }),
                ],
            }),
            { can: { ...can, view_training: false } },
        );

        assert.ok(html.includes('Added to Employee Training'));
        assert.ok(!html.includes('/organization/employees/'));
    });

    it('keeps repeated P2A/P2B phases in timeline order', async () => {
        const html = await renderTimeline(
            assignment({
                current_phase: { id: 33, code: 'p2a', label: 'Join Standby' },
                phase_timeline: [
                    phase({
                        id: 31,
                        sequence: 1,
                        phase_code: 'p2a',
                        phase_label: 'Join Standby',
                        status: 'completed',
                        status_label: 'Completed',
                        actual_end_at: '2026-09-05',
                    }),
                    phase({
                        id: 32,
                        sequence: 2,
                        phase_code: 'p2b',
                        phase_label: 'Training',
                        status: 'completed',
                        status_label: 'Completed',
                        actual_end_at: '2026-09-10',
                    }),
                    phase({
                        id: 33,
                        sequence: 3,
                        phase_code: 'p2a',
                        phase_label: 'Join Standby',
                        status: 'active',
                        status_label: 'Active',
                    }),
                ],
            }),
        );

        assert.ok(html.indexOf('Join Standby') < html.indexOf('Training'));
        assert.ok(html.lastIndexOf('Join Standby') > html.indexOf('Training'));
    });

    it('shows correction affordance only when override permission is granted', async () => {
        const allowed = await renderTimeline(activeCurrentP0Assignment(), {
            can: {
                ...can,
                override_corrections: true,
                view_corrections: false,
                request_correction: false,
            },
        });
        const denied = await renderTimeline(activeCurrentP0Assignment(), {
            can: {
                ...can,
                override_corrections: false,
                view_corrections: false,
                request_correction: false,
            },
        });

        assert.ok(allowed.includes('Correct'));
        assert.ok(!denied.includes('Correct'));
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

    it('does not render raw object metadata on unfamiliar detail shapes', async () => {
        const html = await renderTimeline(
            assignment({
                phase_timeline: [
                    phase({
                        details: {
                            note: 'Safe note',
                            payload: { hidden: true },
                        },
                    }),
                ],
            }),
        );

        assert.ok(html.includes('Safe note'));
        assert.ok(!html.includes('[object Object]'));
        assert.ok(!html.includes('payload'));
    });
});
