import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PhaseTimelineItem } from '../types.ts';
import { buildPhaseMetadataRows } from './phase-timeline-phase-metadata.ts';

function phase(overrides: Partial<PhaseTimelineItem> = {}): PhaseTimelineItem {
    return {
        id: 1,
        sequence: 1,
        phase_code: 'p4',
        phase_label: 'On Vessel',
        status: 'completed',
        status_label: 'Completed',
        planned_start_at: null,
        planned_end_at: null,
        actual_start_at: '2026-09-01',
        actual_end_at: '2026-09-15',
        details: null,
        remarks: null,
        has_pending_correction: false,
        has_approved_correction: false,
        ...overrides,
    };
}

describe('buildPhaseMetadataRows', () => {
    it('formats P2B course and provider with readable labels', () => {
        const rows = buildPhaseMetadataRows(
            phase({
                phase_code: 'p2b',
                phase_label: 'Training',
                details: {
                    course_id: 15,
                    course: 'BOSIET',
                    provider: 'ABC Training',
                },
            }),
        );

        assert.deepEqual(
            rows.map((row) => [row.label, row.value]),
            [
                ['Course', 'BOSIET'],
                ['Training Provider', 'ABC Training'],
            ],
        );
    });

    it('does not expose course_id or raw object values', () => {
        const rows = buildPhaseMetadataRows(
            phase({
                phase_code: 'p2b',
                details: {
                    course_id: '15',
                    course: 'BOSIET',
                    nested: { unsafe: true },
                },
            }),
        );

        assert.equal(
            rows.some((row) => row.key === 'course_id'),
            false,
        );
        assert.equal(
            rows.some((row) => row.key === 'nested'),
            false,
        );
        assert.equal(rows.length, 1);
        assert.equal(rows[0]?.label, 'Course');
    });

    it('preserves other safe phase metadata with humanized labels', () => {
        const rows = buildPhaseMetadataRows(
            phase({
                phase_code: 'p2a',
                details: {
                    hotel_name: 'Crew Hotel',
                },
            }),
        );

        assert.deepEqual(rows, [
            { key: 'hotel_name', label: 'Hotel Name', value: 'Crew Hotel' },
        ]);
    });
});
