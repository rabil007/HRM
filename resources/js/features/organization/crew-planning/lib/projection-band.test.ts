import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PlanningProjectionPeriod } from '../types.ts';
import { periodTitle, projectionBandMode } from './projection-band.ts';

function gapPeriod(
    overrides: Partial<PlanningProjectionPeriod> = {},
): PlanningProjectionPeriod {
    return {
        from: '2026-08-10',
        to: '2026-08-14',
        projected_count: 0,
        gap: 1,
        excess: 0,
        ...overrides,
    };
}

function overlapPeriod(
    overrides: Partial<PlanningProjectionPeriod> = {},
): PlanningProjectionPeriod {
    return {
        from: '2026-08-18',
        to: '2026-08-19',
        projected_count: 2,
        gap: 0,
        excess: 1,
        ...overrides,
    };
}

describe('projectionBandMode', () => {
    it('marks create-enabled projected gaps as actionable', () => {
        assert.equal(projectionBandMode(gapPeriod(), true, true), 'create');
    });

    it('keeps projected gaps inspect-only without create permission', () => {
        assert.equal(projectionBandMode(gapPeriod(), false, true), 'inspect');
        assert.equal(projectionBandMode(gapPeriod(), false, false), 'inspect');
    });

    it('keeps projected gaps inspect-only when no gap handler is provided', () => {
        assert.equal(projectionBandMode(gapPeriod(), true, false), 'inspect');
    });

    it('never makes overlap bands actionable', () => {
        assert.equal(
            projectionBandMode(overlapPeriod(), true, true),
            'inspect',
        );
        assert.equal(
            projectionBandMode(overlapPeriod(), false, false),
            'inspect',
        );
    });
});

describe('periodTitle', () => {
    it('describes projected gaps for inspection with plain terminology', () => {
        assert.equal(
            periodTitle(gapPeriod(), 2),
            [
                'Manning shortfall',
                'Required crew: 2',
                'Available: 0',
                'Short: 1',
                '10-08-2026 → 14-08-2026',
            ].join('\n'),
        );
    });

    it('describes projected overlaps for inspection with plain terminology', () => {
        assert.equal(
            periodTitle(overlapPeriod(), 1),
            [
                'Relief overlap',
                'Required crew: 1',
                'Available: 2',
                'Extra: 1',
                '18-08-2026 → 19-08-2026',
            ].join('\n'),
        );
    });
});
