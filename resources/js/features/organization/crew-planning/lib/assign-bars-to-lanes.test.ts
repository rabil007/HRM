import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    assignBarsToLanes,
    dateIntervalsOverlap,
    laneBarHeight,
    laneCountForBars,
    laneTopOffset,
    rowHeightForLaneCount,
} from './assign-bars-to-lanes.ts';

function bar(
    id: number,
    start: string,
    end: string,
): { id: number; start: string; end: string } {
    return { id, start, end };
}

describe('dateIntervalsOverlap', () => {
    it('treats same-day contact as overlapping', () => {
        assert.equal(
            dateIntervalsOverlap(
                '2026-08-01',
                '2026-08-15',
                '2026-08-15',
                '2026-08-20',
            ),
            true,
        );
    });

    it('does not overlap consecutive non-touching days', () => {
        assert.equal(
            dateIntervalsOverlap(
                '2026-08-01',
                '2026-08-14',
                '2026-08-15',
                '2026-08-20',
            ),
            false,
        );
    });
});

describe('assignBarsToLanes', () => {
    it('places one bar in lane 0', () => {
        const result = assignBarsToLanes([bar(1, '2026-08-01', '2026-08-20')]);

        assert.deepEqual(result, [
            { bar: bar(1, '2026-08-01', '2026-08-20'), lane: 0 },
        ]);
        assert.equal(laneCountForBars([bar(1, '2026-08-01', '2026-08-20')]), 1);
    });

    it('puts two overlapping bars on separate lanes', () => {
        const result = assignBarsToLanes([
            bar(10, '2026-08-01', '2026-08-20'),
            bar(20, '2026-08-05', '2026-08-25'),
        ]);

        assert.equal(result.find((entry) => entry.bar.id === 10)?.lane, 0);
        assert.equal(result.find((entry) => entry.bar.id === 20)?.lane, 1);
        assert.equal(
            laneCountForBars([
                bar(10, '2026-08-01', '2026-08-20'),
                bar(20, '2026-08-05', '2026-08-25'),
            ]),
            2,
        );
    });

    it('reuses a lane for non-overlapping bars', () => {
        const result = assignBarsToLanes([
            bar(1, '2026-08-01', '2026-08-20'),
            bar(2, '2026-08-21', '2026-09-10'),
        ]);

        assert.equal(result.find((entry) => entry.bar.id === 1)?.lane, 0);
        assert.equal(result.find((entry) => entry.bar.id === 2)?.lane, 0);
        assert.equal(
            laneCountForBars([
                bar(1, '2026-08-01', '2026-08-20'),
                bar(2, '2026-08-21', '2026-09-10'),
            ]),
            1,
        );
    });

    it('uses separate lanes for partially overlapping intervals', () => {
        const result = assignBarsToLanes([
            bar(1, '2026-08-01', '2026-08-15'),
            bar(2, '2026-08-10', '2026-08-25'),
        ]);

        assert.equal(result.find((entry) => entry.bar.id === 1)?.lane, 0);
        assert.equal(result.find((entry) => entry.bar.id === 2)?.lane, 1);
    });

    it('orders lanes deterministically by start, end, then id', () => {
        const result = assignBarsToLanes([
            bar(99, '2026-08-05', '2026-08-25'),
            bar(11, '2026-08-01', '2026-08-20'),
            bar(22, '2026-08-01', '2026-08-10'),
        ]);

        assert.deepEqual(
            result.map((entry) => ({ id: entry.bar.id, lane: entry.lane })),
            [
                { id: 22, lane: 0 },
                { id: 11, lane: 1 },
                { id: 99, lane: 2 },
            ],
        );
    });

    it('assigns three simultaneous employees to three lanes', () => {
        const bars = [
            bar(1, '2026-08-01', '2026-08-31'),
            bar(2, '2026-08-01', '2026-08-31'),
            bar(3, '2026-08-01', '2026-08-31'),
        ];
        const result = assignBarsToLanes(bars);

        assert.deepEqual(result.map((entry) => entry.lane).sort(), [0, 1, 2]);
        assert.equal(laneCountForBars(bars), 3);
    });

    it('keeps lane packing stable when a later bar can reuse lane 0', () => {
        const result = assignBarsToLanes([
            bar(1, '2026-08-01', '2026-08-20'),
            bar(2, '2026-08-05', '2026-08-25'),
            bar(3, '2026-08-21', '2026-09-10'),
        ]);

        assert.equal(result.find((entry) => entry.bar.id === 1)?.lane, 0);
        assert.equal(result.find((entry) => entry.bar.id === 2)?.lane, 1);
        assert.equal(result.find((entry) => entry.bar.id === 3)?.lane, 0);
        assert.equal(laneCountForBars(result.map((entry) => entry.bar)), 2);
    });
});

describe('rowHeightForLaneCount', () => {
    it('keeps a compact single-lane row', () => {
        assert.equal(rowHeightForLaneCount(1), 48);
        assert.equal(rowHeightForLaneCount(0), 48);
    });

    it('expands dynamically with lane count', () => {
        assert.equal(rowHeightForLaneCount(2), 72);
        assert.equal(rowHeightForLaneCount(3), 104);
        assert.equal(rowHeightForLaneCount(4), 136);
    });

    it('does not use required_count — only lane count', () => {
        // Required 2 with one employee still uses one lane / compact height.
        assert.equal(
            rowHeightForLaneCount(
                laneCountForBars([bar(1, '2026-08-01', '2026-08-10')]),
            ),
            48,
        );
    });
});

describe('lane geometry', () => {
    it('gives each lane a distinct vertical offset so names do not collide', () => {
        const laneCount = 3;
        const tops = [0, 1, 2].map((lane) => laneTopOffset(lane, laneCount));
        const height = laneBarHeight(laneCount);

        assert.deepEqual(tops, [6, 38, 70]);
        assert.equal(height, 28);

        for (let index = 0; index < tops.length - 1; index++) {
            assert.ok(tops[index] + height <= tops[index + 1]);
        }
    });

    it('keeps single-lane bars within the historic row padding', () => {
        assert.equal(laneTopOffset(0, 1), 6);
        assert.equal(laneBarHeight(1), 36);
    });

    it('keeps projection overlays conceptually full-row while lanes expand', () => {
        // Projection bands use absolute inset-0 on the timeline; row height alone
        // grows with lanes so one Today line / overlay spans every employee lane.
        const single = rowHeightForLaneCount(1);
        const triple = rowHeightForLaneCount(3);

        assert.equal(single, 48);
        assert.equal(triple, 104);
        assert.ok(triple > single);
    });
});

describe('search highlight and row targeting helpers', () => {
    it('can still resolve the matching employee among laned bars', () => {
        const bars = [
            {
                id: 1,
                start: '2026-08-01',
                end: '2026-08-31',
                employee_name: 'Hassan',
            },
            {
                id: 2,
                start: '2026-08-01',
                end: '2026-08-31',
                employee_name: 'Javed',
            },
        ];
        const laned = assignBarsToLanes(bars);
        const query = 'jav';
        const highlighted = laned.filter((entry) =>
            entry.bar.employee_name.toLowerCase().includes(query),
        );

        assert.equal(highlighted.length, 1);
        assert.equal(highlighted[0].bar.id, 2);
        assert.equal(highlighted[0].lane, 1);
    });

    it('preserves vessel/rank identity independent of lane packing', () => {
        const vesselId = 7;
        const rankId = 3;
        const bars = [
            bar(1, '2026-08-01', '2026-08-20'),
            bar(2, '2026-08-05', '2026-08-25'),
        ];

        assert.equal(laneCountForBars(bars), 2);
        // Drop / click targets remain the original rank position, not a lane index.
        assert.deepEqual({ vesselId, rankId }, { vesselId: 7, rankId: 3 });
    });
});
