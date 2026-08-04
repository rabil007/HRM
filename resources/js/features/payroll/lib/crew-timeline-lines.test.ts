import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewTimelineLine } from '../crew-timeline/types.ts';
import {
    formatCrewTimelineDate,
    formatCrewTimelineDateRange,
    formatCrewTimelineDays,
    summarizeCrewTimelineLines,
} from './crew-timeline-lines.ts';

function timelineLine(
    overrides: Partial<CrewTimelineLine> = {},
): CrewTimelineLine {
    return {
        id: 1,
        phase_code: 'on_vessel',
        phase_label: 'On Vessel',
        pay_category: 'onsite',
        pay_category_label: 'Onsite',
        from_date: '2026-08-08',
        to_date: '2026-08-10',
        days: '3.00',
        source_actual_start: '2026-08-08',
        source_actual_end: '2026-08-10',
        warning: null,
        remarks: null,
        ...overrides,
    };
}

describe('crew timeline line presentation', () => {
    it('summarizes excluded and warning lines for the modal overview', () => {
        const summary = summarizeCrewTimelineLines([
            timelineLine(),
            timelineLine({
                id: 2,
                pay_category: 'excluded',
                warning: {
                    code: 'future_actual_date',
                    label: 'Future actual date',
                    is_blocking: false,
                },
            }),
            timelineLine({
                id: 3,
                warning: {
                    code: 'overlap',
                    label: 'Overlapping payable dates',
                    is_blocking: true,
                },
            }),
        ]);

        assert.deepEqual(summary, {
            lineCount: 3,
            excludedLineCount: 1,
            warningCount: 2,
            blockingWarningCount: 1,
        });
    });

    it('formats dates and same-day ranges without ambiguous numeric dates', () => {
        assert.equal(formatCrewTimelineDate('2026-08-04'), '04 Aug 2026');
        assert.equal(
            formatCrewTimelineDateRange('2026-08-04', '2026-08-04'),
            '04 Aug 2026',
        );
        assert.equal(
            formatCrewTimelineDateRange('2026-08-04', '2026-08-07'),
            '04 Aug 2026 – 07 Aug 2026',
        );
        assert.equal(
            formatCrewTimelineDateRange(null, null),
            'No planned dates',
        );
    });

    it('uses readable singular, plural, and fractional day labels', () => {
        assert.equal(formatCrewTimelineDays('1.00'), '1 day');
        assert.equal(formatCrewTimelineDays('3.00'), '3 days');
        assert.equal(formatCrewTimelineDays('0.50'), '0.5 days');
    });
});
