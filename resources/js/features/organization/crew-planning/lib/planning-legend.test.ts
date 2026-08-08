import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';

async function renderLegend(canProjection: boolean): Promise<string> {
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
        const { PlanningLegend } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew-planning/components/planning-legend.tsx',
        );
        const { TooltipProvider } = await vite.ssrLoadModule(
            './resources/js/components/ui/tooltip.tsx',
        );

        return renderToString(
            React.createElement(
                TooltipProvider,
                null,
                React.createElement(PlanningLegend, { canProjection }),
            ),
        );
    } finally {
        await vite.close();
    }
}

describe('PlanningLegend render component test', () => {
    it('renders all legend categories when canProjection is true', async () => {
        const html = await renderLegend(true);

        assert.ok(html.includes('Crew Assigned'));
        assert.ok(html.includes('Relief Planned'));
        assert.ok(html.includes('Planned Crew'));
        assert.ok(html.includes('Current Manning Shortfall'));
        assert.ok(html.includes('Future Manning Shortfall'));
        assert.ok(html.includes('Relief Overlap'));

        assert.ok(!html.includes('Vacant Slot'));
        assert.ok(!html.includes('Vacant Position'));
        assert.ok(!html.includes('Assignment Created'));
        assert.ok(!html.includes('Planned Relief'));
        assert.ok(!html.includes('Projected Gap'));
        assert.ok(!html.includes('Projected Overlap'));
    });

    it('renders only crew bar legend categories when canProjection is false', async () => {
        const html = await renderLegend(false);

        assert.ok(html.includes('Crew Assigned'));
        assert.ok(html.includes('Relief Planned'));
        assert.ok(html.includes('Planned Crew'));

        assert.ok(!html.includes('Current Manning Shortfall'));
        assert.ok(!html.includes('Future Manning Shortfall'));
        assert.ok(!html.includes('Relief Overlap'));

        assert.ok(!html.includes('Vacant Slot'));
        assert.ok(!html.includes('Vacant Position'));
        assert.ok(!html.includes('Assignment Created'));
        assert.ok(!html.includes('Planned Relief'));
        assert.ok(!html.includes('Projected Gap'));
        assert.ok(!html.includes('Projected Overlap'));
    });
});
