import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';
import type { PlanningProjectionRow } from '../types.ts';

async function renderOverlay(
    projection: PlanningProjectionRow,
    today: Date = new Date('2026-08-10T00:00:00Z'),
    canCreate = true,
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
        const { ProjectionOverlay } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew-planning/components/projection-overlay.tsx',
        );
        const { TooltipProvider } = await vite.ssrLoadModule(
            './resources/js/components/ui/tooltip.tsx',
        );

        return renderToString(
            React.createElement(
                TooltipProvider,
                null,
                React.createElement(ProjectionOverlay, {
                    projection,
                    rangeFrom: new Date('2026-08-01T00:00:00Z'),
                    rangeTo: new Date('2026-10-31T00:00:00Z'),
                    today,
                    canCreate,
                    onGapClick: () => {},
                }),
            ),
        );
    } finally {
        await vite.close();
    }
}

describe('ProjectionOverlay render component test', () => {
    it('renders current gap as range band and future gap as compact marker', async () => {
        const projection: PlanningProjectionRow = {
            row_key: 'vessel:1|rank:1',
            vessel_id: 1,
            vessel_name: 'Vessel A',
            rank_id: 1,
            rank_name: 'Captain',
            required_count: 1,
            status: 'future_gap',
            next_gap_date: '2026-09-01',
            minimum_projected_count: 0,
            maximum_gap: 1,
            periods: [
                {
                    from: '2026-08-05',
                    to: '2026-08-12',
                    projected_count: 0,
                    gap: 1,
                    excess: 0,
                },
                {
                    from: '2026-09-01',
                    to: '2026-09-30',
                    projected_count: 0,
                    gap: 1,
                    excess: 0,
                },
            ],
        };

        const html = await renderOverlay(
            projection,
            new Date('2026-08-10T00:00:00Z'),
        );

        // Current gap (starts 2026-08-05 <= 2026-08-10) is rendered as range band without marker attribute
        assert.ok(html.includes('data-projection-band'));

        // Future gap (starts 2026-09-01 > 2026-08-10) renders compact marker
        assert.ok(html.includes('data-projection-future-marker'));
        assert.ok(html.includes('Future Shortfall'));
        assert.ok(html.includes('Future Manning Shortfall'));
        assert.ok(html.includes('Required crew: 1'));
    });

    it('renders multiple future gaps as separate compact markers', async () => {
        const projection: PlanningProjectionRow = {
            row_key: 'vessel:1|rank:1',
            vessel_id: 1,
            vessel_name: 'Vessel B',
            rank_id: 1,
            rank_name: 'Chief Engineer',
            required_count: 1,
            status: 'future_gap',
            next_gap_date: '2026-09-01',
            minimum_projected_count: 0,
            maximum_gap: 1,
            periods: [
                {
                    from: '2026-09-01',
                    to: '2026-09-15',
                    projected_count: 0,
                    gap: 1,
                    excess: 0,
                },
                {
                    from: '2026-10-01',
                    to: '2026-10-15',
                    projected_count: 0,
                    gap: 1,
                    excess: 0,
                },
            ],
        };

        const html = await renderOverlay(
            projection,
            new Date('2026-08-10T00:00:00Z'),
        );

        const count = (html.match(/data-projection-future-marker/g) || [])
            .length;
        assert.equal(count, 2);
    });

    it('renders relief overlap as range band', async () => {
        const projection: PlanningProjectionRow = {
            row_key: 'vessel:1|rank:1',
            vessel_id: 1,
            vessel_name: 'Vessel C',
            rank_id: 1,
            rank_name: 'Chief Officer',
            required_count: 1,
            status: 'overlap',
            next_gap_date: null,
            minimum_projected_count: 1,
            maximum_gap: 0,
            periods: [
                {
                    from: '2026-08-15',
                    to: '2026-08-20',
                    projected_count: 2,
                    gap: 0,
                    excess: 1,
                },
            ],
        };

        const html = await renderOverlay(
            projection,
            new Date('2026-08-10T00:00:00Z'),
        );

        assert.ok(html.includes('data-projection-band'));
        assert.ok(!html.includes('data-projection-future-marker'));
        assert.ok(html.includes('Relief overlap'));
    });
});
