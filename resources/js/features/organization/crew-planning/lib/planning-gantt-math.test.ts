import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    assignmentBarPositionStyle,
    assignmentDurationDays,
    barPositionStyle,
    daysBetween,
    inclusivePeriodPositionStyle,
    pxToDays,
    shiftDateRange,
} from './planning-gantt-math.ts';

describe('assignmentDurationDays', () => {
    it('calculates 60-day tour duration from planned join through exclusive planned leave', () => {
        // Join: 14-Jun-2026, Planned sign-off: 13-Aug-2026 = 60 days
        assert.equal(assignmentDurationDays('2026-06-14', '2026-08-13'), 60);
    });

    it('calculates 90-day tour duration from planned join through exclusive planned leave', () => {
        // Join: 14-Jun-2026, Planned sign-off: 12-Sep-2026 = 90 days
        assert.equal(assignmentDurationDays('2026-06-14', '2026-09-12'), 90);
    });

    it('calculates 150-day tour duration from planned join through exclusive planned leave', () => {
        // Join: 14-Jun-2026, Planned sign-off: 11-Nov-2026 = 150 days
        assert.equal(assignmentDurationDays('2026-06-14', '2026-11-11'), 150);
    });

    it('returns 0 when start equals leave date without inventing a 1-day duration', () => {
        assert.equal(assignmentDurationDays('2026-06-14', '2026-06-14'), 0);
    });
});

describe('barPositionStyle (Exclusive End Boundary)', () => {
    const rangeFrom = new Date(Date.UTC(2026, 5, 1)); // 2026-06-01
    const rangeTo = new Date(Date.UTC(2026, 7, 31)); // 2026-08-31 (92 total calendar days)

    it('positions Gantt assignment bar width following exclusive end boundary', () => {
        const style = assignmentBarPositionStyle(
            '2026-06-14',
            '2026-08-13',
            rangeFrom,
            rangeTo,
        );

        assert.notDeepEqual(style, { display: 'none' });
        if ('left' in style) {
            // 13 days from June 1 to June 14 = (13 / 92) * 100%
            const expectedLeft = (13 / 92) * 100;
            // 60 days duration from June 14 to August 13 = (60 / 92) * 100%
            const expectedWidth = (60 / 92) * 100;

            assert.equal(
                parseFloat(style.left).toFixed(4),
                expectedLeft.toFixed(4),
            );
            assert.equal(
                parseFloat(style.width).toFixed(4),
                expectedWidth.toFixed(4),
            );
        }
    });

    it('stops bar at the beginning of the planned leave date', () => {
        const style = barPositionStyle(
            '2026-06-14',
            '2026-06-15',
            rangeFrom,
            rangeTo,
        );

        assert.notDeepEqual(style, { display: 'none' });
        if ('left' in style) {
            // 1 day width out of 92 days
            const expectedWidth = (1 / 92) * 100;

            assert.equal(
                parseFloat(style.width).toFixed(4),
                expectedWidth.toFixed(4),
            );
        }
    });

    it('clips correctly at selected timeline boundaries', () => {
        // Starts before timeline range
        const leftClipped = barPositionStyle(
            '2026-05-01',
            '2026-06-15',
            rangeFrom,
            rangeTo,
        );
        assert.notDeepEqual(leftClipped, { display: 'none' });
        if ('left' in leftClipped) {
            assert.equal(leftClipped.left, '0%');
        }

        // Ends after timeline range
        const rightClipped = barPositionStyle(
            '2026-08-15',
            '2026-10-01',
            rangeFrom,
            rangeTo,
        );
        assert.notDeepEqual(rightClipped, { display: 'none' });
        if ('width' in rightClipped) {
            // 16 days remaining in timeline out of 92
            const expectedWidth = ((92 - 75) / 92) * 100;
            assert.equal(
                parseFloat(rightClipped.width).toFixed(4),
                expectedWidth.toFixed(4),
            );
        }

        // Entirely outside timeline range
        const outOfBounds = barPositionStyle(
            '2026-04-01',
            '2026-05-01',
            rangeFrom,
            rangeTo,
        );
        assert.deepEqual(outOfBounds, { display: 'none' });
    });

    it('extends open-ended bar through the end of the timeline', () => {
        const openEndedStyle = barPositionStyle(
            '2026-06-14',
            '2026-08-31',
            rangeFrom,
            rangeTo,
            true,
        );

        assert.notDeepEqual(openEndedStyle, { display: 'none' });
        if ('left' in openEndedStyle) {
            // June 14 to end of August 31 (79 days out of 92)
            const expectedWidth = (79 / 92) * 100;
            assert.equal(
                parseFloat(openEndedStyle.width).toFixed(4),
                expectedWidth.toFixed(4),
            );
        }
    });
});

describe('inclusivePeriodPositionStyle (Projection Overlay)', () => {
    const rangeFrom = new Date(Date.UTC(2026, 5, 1));
    const rangeTo = new Date(Date.UTC(2026, 7, 31));

    it('positions projection overlay using inclusive period dates', () => {
        const style = inclusivePeriodPositionStyle(
            '2026-08-10',
            '2026-08-14',
            rangeFrom,
            rangeTo,
        );

        assert.notDeepEqual(style, { display: 'none' });
        if ('left' in style) {
            // 5 full calendar days (10, 11, 12, 13, 14) out of 92
            const expectedWidth = (5 / 92) * 100;
            assert.equal(
                parseFloat(style.width).toFixed(4),
                expectedWidth.toFixed(4),
            );
        }
    });
});

describe('drag and resize math helpers', () => {
    const rangeFrom = new Date(Date.UTC(2026, 5, 1));
    const rangeTo = new Date(Date.UTC(2026, 7, 31));

    it('shifts date ranges preserving interval duration', () => {
        const shifted = shiftDateRange('2026-06-14', '2026-08-13', 10);
        assert.deepEqual(shifted, {
            start: '2026-06-24',
            end: '2026-08-23',
        });
        assert.equal(daysBetween(shifted.start, shifted.end), 60);
    });

    it('converts pixel delta to timeline days', () => {
        const days = pxToDays(100, 920, rangeFrom, rangeTo);
        assert.equal(Math.round(days), 10);
    });
});
